<?php
namespace MifuminLib\Mifuminator;

class Mifuminator {
    private $db_file_path;
    private $tmp_dir;
    private $log_dir;

    private $db;

    const ANSWER_YES = 1;
    const ANSWER_NO = 2;
    const ANSWER_DONT_KNOW = 3;
    const ANSWER_PROBABLY = 4;
    const ANSWER_PROBABLY_NOT = 5;

    const STATE_ASK = 1;
    const STATE_SUGGEST = 2;
    const STATE_CORRECT = 3;
    const STATE_WRONG = 4;
    const STATE_YOUWIN = 5;

    // オプション的なパブリックフィールド

    // 回答内容ごとのスコア
    public $score = [
        self::ANSWER_YES          => 10,
        self::ANSWER_NO           => -10,
        self::ANSWER_DONT_KNOW    => 0,
        self::ANSWER_PROBABLY     => 1,
        self::ANSWER_PROBABLY_NOT => -1,
    ];

    // 統計スコアの最大値
    public $score_max = 100;

    // 候補のうちトップからこれだけ引き離されていると候補から除外
    public $cutoff_difference = 2000;

    // 未知の質問を投げかける確率(%)
    public $try_unknown_question_rate = 5;

    // ロジスティック回帰の計数
    public $logistic_regression_param = 0.054;

    // 何回目で答えを言うか
    public $suggest_timings = [20, 40, 50];

    // 最後の1問を学習優先で選ぶ機能を使うか
    public $use_final_learning = TRUE;

    // この回数だけ連続で同じ回答をした場合に、今までと違う回答を期待できる質問を探す(0で無効)
    public $avoid_same_answer_number = 4;

    public function __construct($db_file_path, $tmp_dir, $log_dir)
    {
        $this->db_file_path = $db_file_path;
        $this->tmp_dir = $tmp_dir;
        $this->log_dir = $log_dir;
        $this->db = new \PDO('sqlite:'.$db_file_path);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('RANDOM', 'mt_rand', 0);
    }

    public function addTarget($target, $user_id = NULL)
    {
        $this->insertToTable('target', ['content' => $target, 'create_user_id' => $user_id, 'update_user_id' => $user_id]);
    }

    public function addQuestion($question, $user_id = NULL)
    {
        $this->insertToTable('question', ['content' => $question, 'create_user_id' => $user_id, 'update_user_id' => $user_id]);
    }

    public function analyze()
    {
        $count = array();
        $total_score = array();
        foreach (glob($this->log_dir.'*.log') as $file) {
            if (basename($file)==$this->getLogFileName()) continue;
            $this->analyzeFile($file, $count, $total_score);
        }

        $score_power = $this->score_max / $this->score[self::ANSWER_YES];
        $this->db->beginTransaction();
        foreach ($count as $target_id => $count_row) {
            foreach ($count_row as $question_id => $count_value) {
                $average_score = $total_score[$target_id][$question_id] / $count_value;
                $population_power = 1/(1+exp(-$this->logistic_regression_param*($count_value-1)));
                $final_score = (int)($average_score * $population_power * $score_power);
                $this->setScore($question_id, $target_id, $final_score);
            }
        }
        $this->db->exec('DELETE FROM game_state WHERE create_date < DATETIME(\'now\', \'-1 day\');');
        $this->db->commit();
        $this->db->exec('VACUUM;');
        return TRUE;
    }

    public function analyzeFile($file, &$count, &$total_score)
    {
        $handle = fopen($file, 'r');
        while (($array = fgetcsv($handle)) !== FALSE) {
            if (count($array)<5) break;
            $timestamp = $array[0];
            $user_id = $array[1];
            $game_id = $array[2];
            $target_id = $array[3];
            for ($i=4; $i<count($array); $i++) {
                list($question_id, $answer) = explode('=', $array[$i]);
                $count[$target_id][$question_id]++;
                $total_score[$target_id][$question_id] += $this->score[trim($answer)];
            }
        }
        fclose($handle);
    }

    public function answer($game_state, $answer)
    {
        $game_state['question_answer_history'][$game_state['question']['question_id']] = $answer;
        $game_state['targets'] = $this->guessTarget($game_state['question_answer_history'], 100, 10, $game_state['except_target_ids']);
        $game_state['best_target_ids'] = $this->getBestTargetIDs($game_state['targets'], 0);

        if (count($game_state['best_target_ids'])==1 || in_array($game_state['stage_number'], $this->suggest_timings)) {
            if (!$this->use_final_learning || $game_state['asked_unknown_question'] || $game_state['stage_number']>=$this->suggest_timings[0]) {
                $game_state['question'] = NULL;
                $game_state['state'] = self::STATE_SUGGEST;
            }else {
                $game_state['asked_unknown_question'] = TRUE;
                $game_state['question'] = $this->nextQuestion($game_state['question_answer_history'], $game_state['best_target_ids'], 'getQuestionScoreSqlUnknown');
                $game_state['stage_number']++;
                $game_state['state'] = self::STATE_ASK;
            }
        }else {
            $game_state['question'] = $this->nextQuestion($game_state['question_answer_history'], $game_state['best_target_ids']);
            if ($game_state['question']['function']=='getQuestionScoreSqlUnknown') {
                $game_state['asked_unknown_question'] = TRUE;
            }
            $game_state['stage_number']++;
            $game_state['state'] = self::STATE_ASK;
        }

        return $this->nextGameState($game_state);
    }

    public function checkAnswer($game_state, $correct)
    {
        if ($correct) {
            $game_state['final_target'] = $game_state['targets'][0];
            $this->writeLog($game_state['user_id'], $game_state['game_id'], $game_state['final_target']['target_id'], $game_state['qustion_answer_history'], NULL, TRUE);
            $game_state['state'] = self::STATE_CORRECT;
        }else {
            $game_state['except_target_ids'] = $game_state['targets']['target_id'];
            $game_state['state'] = self::STATE_WRONG;
        }
        $this->deleteGameState($game_state['game_id']);

        return $this->nextGameState($game_state);
    }

    public function deleteGameState($game_id)
    {
        $statement = $this->db->prepare('DELETE FROM game_state WHERE game_id = :game_id;');
        return $statement->execute(['game_id' => $game_id]);
    }

    public function getDB()
    {
        return $this->db;
    }

    public function generateGameID($user_id)
    {
        return md5($user_id.microtime());
    }

    public function generateStageID($user_id, $game_id)
    {
        return md5($user_id.$game_id.microtime());
    }

    public function getBestTargetIDs($targets, $min = 1)
    {
        $highscore = 0;
        $result = [];
        foreach ($targets as $target) {
            if ($i >= $min) {
                if ($target['score'] <= 0) break;
                if ($target['score'] <= $highscore - $this->cutoff_difference) break;
            }
            $result[] = $target['target_id'];
            if ($i==0) $highscore = $target['score'];
            $i++;
        }
        return $result;
    }

    public function getGameState($user_id, $stage_id)
    {
        $statement = $this->db->prepare('SELECT data FROM game_state WHERE user_id = :user_id AND stage_id = :stage_id;');
        $statement->execute(['user_id'=>$user_id,'stage_id'=>$stage_id]);
        $data = $statement->fetchColumn(0);
        return $data?unserialize($data):NULL;
    }

    public function getQuestionScoreSqlDivideHalf($question_answer_history, $temp_targets)
    {
        $score_sql = '
            (
                SELECT
                    MIN(
                        SUM(CASE WHEN score > 0 THEN 1 ELSE 0 END)
                        ,
                        SUM(CASE WHEN score < 0 THEN 1 ELSE 0 END)
                    )
                FROM score
                WHERE score.question_id = question.question_id
                '.(count($temp_targets)>1?'AND target_id IN ('.implode(',', $temp_targets).')':'').'
            )
        ';
        return $score_sql;
    }

    public function getQuestionScoreSqlDivideTop2($question_answer_history, $temp_targets)
    {
        if (count($temp_targets)>1) {
            $score_sql = '
                ABS((
                    SELECT
                        CASE WHEN a IS NOT NULL THEN
                            CASE WHEN b IS NOT NULL THEN
                                ABS(a-b)*10
                            ELSE
                                ABS(a)
                            END
                        ELSE
                            CASE WHEN b IS NOT NULL THEN
                                ABS(b)
                            ELSE
                                0
                            END
                        END
                        score
                    FROM (
                        SELECT
                            (
                                SELECT score
                                FROM score
                                WHERE score.question_id = question.question_id
                                AND score.target_id = '.$temp_targets[0].'
                            ) a,
                            (
                                SELECT score
                                FROM score
                                WHERE score.question_id = question.question_id
                                AND score.target_id = '.$temp_targets[1].'
                            ) b
                    ) pp
                ))
            ';
        }else {
            $score_sql = '0';
        }
        return $score_sql;
    }

    // 質問に全く優劣をつけない
    // (デバッグ用に使うことがあるかもしれない程度)
    public function getQuestionScoreSqlFlat($question_answer_history, $temp_targets)
    {
        return '0';
    }

    public function getQuestionScoreSqlManyNo($question_answer_history, $temp_targets)
    {
        $score_sql = '
            (
                SELECT
                    SUM(CASE WHEN score < 0 THEN 1 ELSE 0 END)
                FROM score
                WHERE score.question_id = question.question_id
                '.(count($temp_targets)>0?'AND target_id IN ('.implode(',', $temp_targets).')':'').'
            )
        ';
        return $score_sql;
    }

    public function getQuestionScoreSqlManyYes($question_answer_history, $temp_targets)
    {
        $score_sql = '
            (
                SELECT
                    SUM(CASE WHEN score > 0 THEN 1 ELSE 0 END)
                FROM score
                WHERE score.question_id = question.question_id
                '.(count($temp_targets)>0?'AND target_id IN ('.implode(',', $temp_targets).')':'').'
            )
        ';
        return $score_sql;
    }

    public function getQuestionScoreSqlUnknown($question_answer_history, $temp_targets)
    {
        if (count($temp_targets)>0) {
            $target_count = 'COALESCE(SUM(CASE WHEN target_id IN ('.implode(',', $temp_targets).') THEN -(SELECT COUNT(question_id) FROM question) ELSE 0 END), 0)';
        }else {
            $target_count = '';
        }
        return '
            (
                SELECT '.$target_count.'-(CASE WHEN SUM(CASE WHEN score>0 THEN 1 ELSE 0 END)>0 AND SUM(CASE WHEN score<0 THEN 1 ELSE 0 END)>0 THEN COUNT(*) ELSE 0 END)
                FROM score
                WHERE score.question_id = question.question_id
            )
        ';
    }

    public function getQuestionScoreSqlWellKnown($question_answer_history, $temp_targets)
    {
        $score_sql = '
            (
                SELECT
                    SUM(CASE WHEN score <> 0 THEN 1 ELSE 0 END)
                FROM score
                WHERE score.question_id = question.question_id
                '.(count($temp_targets)>0?'AND target_id IN ('.implode(',', $temp_targets).')':'').'
            )
        ';
        return $score_sql;
    }

    public function getLogFileName($time=NULL)
    {
        if ($time===NULL) $time = time();
        return $this->log_dir.date('Ymd', $time).'.log';
    }

    public function guessTarget($question_answer_history, $max = 1, $min = 1, $except_target_ids = [])
    {
        $whenthen = '';
        $qcsv = '';
        foreach ($question_answer_history as $question_id => $answer) {
            $qscore = $this->score[$answer];
            if ($qscore==0) continue;
            $whenthen .= "\nWHEN $question_id THEN $qscore";
            if (strlen($qcsv)>0) $qcsv .= ',';
            $qcsv .= $question_id;
        }
        if (strlen($qcsv)==0) {
            return [];
        }
        $except_target_sql = '';
        if (count($except_target_ids)>0) {
            $except_target_sql = 'AND target_id NOT IN ('.implode(',',$except_target_ids).')';
        }
        $ret = $this->getDB()->query('
            SELECT
                target_id,
                content,
                COALESCE((
                    SELECT
                        SUM(score * CASE question_id
                        '.$whenthen.'
                        END
                        )
                    FROM score
                    WHERE score.target_id = target.target_id
                    AND score.question_id IN ('.$qcsv.')
                ), 0) score
            FROM target
            WHERE deleted = 0
            AND equal_to IS NULL
            '.$except_target_sql.'
            ORDER BY score DESC, RANDOM()
            LIMIT '.$max.'
        ');
        $result = [];
        $i = 0;
        $highscore = 0;
        foreach ($ret as $row) {
            if ($i >= $min) {
                if ($row['score'] <= 0) break;
                if ($row['score'] <= $highscore - $this->cutoff_difference) break;
            }
            $result[] = $row;
            if ($i==0) $highscore = $row['score'];
            $i++;
        }
        return $result;
    }

    // 挿入
    public function insertToTable($table, $params, $tryReplace = FALSE, $throwOnConflict = TRUE)
    {
        $inparams = [];
        foreach ($params as $key => $value) {
            $inparams[':'.$key] = $value;
        }
        $sql = 'INSERT'.($tryReplace?' OR REPLACE':($throwOnConflict?'':' OR IGNORE')).' INTO '.$table.' ('.implode(',', array_keys($params)).') VALUES ('.implode(',', array_keys($inparams)).')';
        $statement = $this->db->prepare($sql);
        if ($statement===FALSE) {
            return FALSE;
        }else {
            $statement->execute($inparams);
            return TRUE;
        }
    }

    public function install()
    {
        $this->installTargetTable();
        $this->installQuestionTable();
        $this->installScoreTable();
        $this->installGameStateTable();
    }

    public function installTargetTable()
    {
        $this->db->exec('
            CREATE TABLE target(
                target_id INTEGER PRIMARY KEY AUTOINCREMENT,
                content TEXT NOT NULL UNIQUE,
                equal_to INTEGER NULL,
                create_user_id TEXT NULL,
                create_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                update_user_id TEXT NULL,
                update_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted BOOLEAN NOT NULL DEFAULT 0
            );
        ');
    }

    public function installQuestionTable()
    {
        $this->db->exec('
            CREATE TABLE question(
                question_id INTEGER PRIMARY KEY AUTOINCREMENT,
                content TEXT NOT NULL UNIQUE,
                equal_to INTEGER NULL,
                create_user_id TEXT NULL,
                create_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                update_user_id TEXT NULL,
                update_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted BOOLEAN NOT NULL DEFAULT 0
            );
        ');
    }

    public function installScoreTable()
    {
        $this->db->exec('
            CREATE TABLE score(
                question_id INTEGER NOT NULL,
                target_id INTEGER NOT NULL,
                score INTEGER NOT NULL
            );
        ');
        $this->db->exec('
            CREATE UNIQUE INDEX score_question_target
                ON score (question_id, target_id);
        ');
        $this->db->exec('
            CREATE UNIQUE INDEX score_target_question
                ON score (target_id, question_id);
        ');
    }

    public function installGameStateTable()
    {
        $this->db->exec('
            CREATE TABLE game_state(
                user_id TEXT NOT NULL,
                game_id TEXT NOT NULL,
                stage_id TEXT NOT NULL,
                create_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                data TEXT
            );
        ');
        $this->db->exec('
            CREATE UNIQUE INDEX game_state_user_stage
                ON game_state (user_id, stage_id);
        ');
        $this->db->exec('
            CREATE INDEX game_state_user_game
                ON game_state (user_id, game_id);
        ');
        $this->db->exec('
            CREATE INDEX game_state_create_date
                ON game_state (create_date);
        ');
    }

    public function nextGameState($game_state, $state=NULL)
    {
        if ($state) $game_state['state'] = $state;
        if ($game_state['stage_id']) $game_state['stage_id_history'][] = $game_state['stage_id'];
        $game_state['stage_id'] = $this->generateStageID($game_state['user_id'], $game_state['game_id']);
        $this->db->beginTransaction();
        $this->setGameState($game_state);
        $this->db->commit();
        return $game_state;
    }

    public function nextQuestion($question_answer_history = [], $temp_targets = [], $function = NULL)
    {
        if ($function) {
            $except_sql = '';
            if (count($question_answer_history)>0) {
                $except_sql = 'AND question_id NOT IN ('.implode(',',array_keys($question_answer_history)).')';
            }
            $score_sql = $this->$function($question_answer_history, $temp_targets);
            $ret = $this->db->query('
                SELECT *
                    , '.$score_sql.' score
                FROM question
                WHERE deleted = 0
                AND equal_to IS NULL
                '.$except_sql.'
                ORDER BY score DESC, RANDOM()
                LIMIT 1;
            ');
            $question = $ret->fetch();
            $question['function'] = $function;
            $question['score_sql'] = $score_sql;
            return $question;
        }else {
            // ワンパターン回避
            if ($this->avoid_same_answer_number>0 && count($question_answer_history)>=$this->avoid_same_answer_number) {
                $yes = TRUE;
                $no = TRUE;
                $dontknow = TRUE;
                $answers = array_values($question_answer_history);
                for ($i=1; $i<=$this->avoid_same_answer_number; $i++) {
                    switch ($answers[count($answers)-$i]) {
                        case self::ANSWER_YES:
                        case self::ANSWER_PROBABLY:
                            $no = FALSE;
                            $dontknow = FALSE;
                            break;
                        case self::ANSWER_NO:
                        case self::ANSWER_PROBABLY_NOT:
                            $yes = FALSE;
                            $dontknow = FALSE;
                            break;
                        case self::ANSWER_DONT_KNOW:
                            $yes = FALSE;
                            $no = FALSE;
                            break;
                        default:
                            $yes = FALSE;
                            $no = FALSE;
                            $dontknow = FALSE;
                            break;
                    }
                }
                if ($yes) {
                    $question = $this->nextQuestion($question_answer_history, $temp_targets, 'getQuestionScoreSqlManyNo');
                }else if ($no) {
                    $question = $this->nextQuestion($question_answer_history, $temp_targets, 'getQuestionScoreSqlManyYes');
                }else if ($dontknow) {
                    $question = $this->nextQuestion($question_answer_history, $temp_targets, 'getQuestionScoreSqlWellKnown');
                }else {
                    $question = ['score' => 0];
                }
                if ($question['score']!=0) {
                    return $question;
                }
            }

            // ランダムで未知の質問を出す
            if (mt_rand(0, 99)<$this->try_unknown_question_rate) {
                return $this->nextQuestion($question_answer_history, $temp_targets, 'getQuestionScoreSqlUnknown');
            }

            // 判断に有利な質問を選ぶ
            if (count($temp_targets)==2) {
                $question = $this->nextQuestion($question_answer_history, $temp_targets, 'getQuestionScoreSqlDivideTop2');
                if ($question['score']!=0) {
                    return $question;
                }
            }
            $question = $this->nextQuestion($question_answer_history, $temp_targets, 'getQuestionScoreSqlDivideHalf');
            if ($question['score']!=0) {
                return $question;
            }
            $question = $this->nextQuestion($question_answer_history, $temp_targets, 'getQuestionScoreSqlDivideTop2');
            if ($question['score']!=0) {
                return $question;
            }

            // どうしようもないので次回にご期待ください
            return $this->nextQuestion($question_answer_history, $temp_targets, 'getQuestionScoreSqlUnknown');
        }
    }

    public function setGameState($game_state)
    {
        $params = [
            'user_id' => $game_state['user_id'],
            'game_id' => $game_state['game_id'],
            'stage_id' => $game_state['stage_id'],
            'data' => serialize($game_state),
        ];
        $this->insertToTable('game_state', $params);
    }

    public function setScore($question_id, $target_id, $score, $replace=TRUE)
    {
        $this->insertToTable('score', ['question_id' => $question_id, 'target_id' => $target_id, 'score' => $score], $replace, FALSE);
    }

    public function startGame($user_id)
    {
        $game_state = [];
        $game_id = $this->generateGameID($user_id);
        $game_state['user_id'] = $user_id;
        $game_state['game_id'] = $game_id;
        $game_state['question'] = $this->nextQuestion();
        $game_state['stage_number'] = 1;
        $game_state['question_answer_history'] = [];
        $game_state['targets'] = [];
        $game_state['best_target_ids'] = [];
        $game_state['except_targets'] = [];
        return $this->nextGameState($game_state, self::STATE_ASK);
    }

    public function writeLog($user_id, $game_id, $target_id, $question_answer_list, $time=NULL, $insertToDB=FALSE)
    {
        if ($time===NULL) $time = time();
        $line = date('c', time()).','.$user_id.','.$game_id.','.$target_id;
        if ($insertToDB) $this->db->beginTransaction();
        foreach ($question_answer_list as $question_id => $answer) {
            $line .= ','.$question_id.'='.$answer;
            if ($insertToDB) $this->setScore($question_id, $target_id, (int)(0.5 * $this->score[$answer] * $this->score_max / $this->score[self::ANSWER_YES]), FALSE);
        }
        if ($insertToDB) $this->db->commit();
        file_put_contents($this->getLogFileName($time), $line."\n", FILE_APPEND);
    }
}
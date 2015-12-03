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

    // オプション的なパブリックフィールド

    // 回答内容ごとのスコア
    public $score = [
        self::ANSWER_YES          => 10,
        self::ANSWER_NO           => -10,
        self::ANSWER_DONT_KNOW    => 0,
        self::ANSWER_PROBABLY     => 1,
        self::ANSWER_PROBABLY_NOT => -1,
    ];

    // 何人分ぐらいの回答が集まったら信用できるとみなすか
    public $required_population = 100;

    // スコアの最大値
    public $score_max = 100;

    // 未知の質問を投げかける確率(%)
    public $try_unknown_question_rate = 5;

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
        $this->db->exec('DELETE FROM score;');
        foreach ($count as $target_id => $count_row) {
            foreach ($count_row as $question_id => $count_value) {
                $average_score = $total_score[$target_id][$question_id] / $count_value;
                $population_power = min(1, $count_value / $this->required_population);
                $final_score = (int)($average_score * $population_power * $score_power);
                if ($final_score==0) continue;
                $this->setScore($question_id, $target_id, $final_score);
            }
        }
        $this->db->commit();
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

    public function getDB()
    {
        return $this->db;
    }

    public function getQuestionScoreSql($qustion_answer_history, $temp_targets)
    {
        if (mt_rand(0, 99)<$this->try_unknown_question_rate) return $this->getQuestionScoreSqlUnknown($qustion_answer_history, $temp_targets);
        return $this->getQuestionScoreSqlDivideHalf($qustion_answer_history, $temp_targets);
    }

    public function getQuestionScoreSqlDivideHalf($qustion_answer_history, $temp_targets)
    {
        if (count($temp_targets)>1) {
            $score_sql = '
                (
                    SELECT
                        SUM(CASE WHEN score > 0 OR score < 0 THEN 1 ELSE 0 END)
                        -
                        ABS(
                            SUM(CASE WHEN score > 0 THEN 1 ELSE 0 END)
                            -
                            SUM(CASE WHEN score < 0 THEN 1 ELSE 0 END)
                        )
                    FROM score
                    WHERE score.question_id = question.question_id
                    AND target_id IN ('.implode(',', $temp_targets).')
                )
            ';
        }else {
            $score_sql = '
                (
                    SELECT
                        SUM(CASE WHEN score > 0 OR score < 0 THEN 1 END)
                        -
                        ABS(
                            SUM(CASE WHEN score > 0 THEN 1 END)
                            -
                            SUM(CASE WHEN score < 0 THEN 1 END)
                        )
                    FROM score
                    WHERE score.question_id = question.question_id
                )
            ';
        }
        return $score_sql;
    }

    // 質問に全く優劣をつけない
    // (デバッグ用に使うことがあるかもしれない程度)
    public function getQuestionScoreSqlFlat($qustion_answer_history, $temp_targets)
    {
        return '0';
    }

    public function getQuestionScoreSqlUnknown($qustion_answer_history, $temp_targets)
    {
        return '
            (
                SELECT -COUNT(*)
                FROM score
                WHERE score.question_id = question.question_id
            )
        ';
    }

    public function getLogFileName($time=NULL)
    {
        if ($time===NULL) $time = time();
        return $this->log_dir.date('Ymd', $time).'.log';
    }

    public function guessTarget($qustion_answer_history, $limit = 1)
    {
        $whenthen = '';
        $qcsv = '';
        foreach ($qustion_answer_history as $question_id => $answer) {
            $qscore = $this->score[$answer];
            if ($qscore==0) continue;
            $whenthen .= "\nWHEN $question_id THEN $qscore";
            if (strlen($qcsv)>0) $qcsv .= ',';
            $qcsv .= $question_id;
        }
        if (strlen($qcsv)==0) {
            return [];
        }
        $ret = $this->getDB()->query('
            SELECT
                *
            FROM (
                SELECT
                    target_id,
                    content,
                    (
                        SELECT
                            SUM(score * CASE question_id
                            '.$whenthen.'
                            END
                            )
                        FROM score
                        WHERE score.target_id = target.target_id
                        AND score.question_id IN ('.$qcsv.')
                    ) score
                FROM target
                WHERE deleted = 0
                AND equal_to IS NULL
                ORDER BY score DESC, RANDOM()
                LIMIT '.$limit.'
            )
            WHERE score > 0;
        ');
        return $ret->fetchAll();
    }

    // 挿入
    public function insertToTable($table, $params, $tryReplace = FALSE)
    {
        $inparams = [];
        foreach ($params as $key => $value) {
            $inparams[':'.$key] = $value;
        }
        $sql = 'INSERT '.($tryReplace?'OR REPLACE ':'').'INTO '.$table.' ('.implode(',', array_keys($params)).') VALUES ('.implode(',', array_keys($inparams)).')';
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

    public function nextQuestion($qustion_answer_history = [], $temp_targets = [])
    {
        $except_sql = '';
        if (count($qustion_answer_history)>0) {
            $except_sql = 'AND question_id NOT IN ('.implode(',',array_keys($qustion_answer_history)).')';
        }
        $score_sql = $this->getQuestionScoreSql($qustion_answer_history, $temp_targets);
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
        $row = $ret->fetch();
        $row['stage_id'] = mt_rand();
        return $row;
    }

    public function setScore($question_id, $target_id, $score)
    {
        $this->insertToTable('score', ['question_id' => $question_id, 'target_id' => $target_id, 'score' => $score], TRUE);
    }

    public function writeLog($user_id, $game_id, $target_id, $question_answer_list, $time=NULL)
    {
        if ($time===NULL) $time = time();
        $line = date('c', time()).','.$user_id.','.$game_id.','.$target_id;
        foreach ($question_answer_list as $question_id => $answer) {
            $line .= ','.$question_id.'='.$answer;
        }
        file_put_contents($this->getLogFileName($time), $line."\n", FILE_APPEND);
    }
}
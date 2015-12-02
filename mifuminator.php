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

    public function getLogFileName($time=NULL)
    {
        if ($time===NULL) $time = time();
        return $this->log_dir.date('Ymd', $time).'.log';
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

    public function nextQuestion($user_id, $game_id, $stage_id)
    {
        $ret = $this->db->query('SELECT * FROM question WHERE deleted = 0 AND equal_to IS NULL ORDER BY RANDOM() LIMIT 1;');
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
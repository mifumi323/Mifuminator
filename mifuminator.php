<?php
namespace MifuminLib\Mifuminator;

class Mifuminator {
    private $db_file_path;
    private $tmp_dir;
    private $log_dir;

    private $db;

    public function __construct($db_file_path, $tmp_dir, $log_dir)
    {
        $this->db_file_path = $db_file_path;
        $this->tmp_dir = $tmp_dir;
        $this->log_dir = $log_dir;
        $this->db = new \PDO('sqlite:'.$db_file_path);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('RANDOM', 'mt_rand', 0);
    }

    public function addAnswer($answer, $user_id = NULL)
    {
        $this->insertToTable('answer', ['content' => $answer, 'create_user_id' => $user_id, 'update_user_id' => $user_id]);
    }

    public function addQuestion($question, $user_id = NULL)
    {
        $this->insertToTable('question', ['content' => $question, 'create_user_id' => $user_id, 'update_user_id' => $user_id]);
    }

    public function getDB()
    {
        return $this->db;
    }

    // 挿入
    public function insertToTable($table, $params)
    {
        $inparams = [];
        foreach ($params as $key => $value) {
            $inparams[':'.$key] = $value;
        }
        $sql = 'INSERT INTO '.$table.' ('.implode(',', array_keys($params)).') VALUES ('.implode(',', array_keys($inparams)).')';
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
        $this->installAnswerTable();
        $this->installQuestionTable();
        $this->installScoreTable();
    }

    public function installAnswerTable()
    {
        $this->db->exec('
            CREATE TABLE answer(
                answer_id INTEGER PRIMARY KEY AUTOINCREMENT,
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
                answer_id INTEGER NOT NULL,
                score INTEGER NOT NULL
            );
        ');
        $this->db->exec('
            CREATE UNIQUE INDEX score_question_answer
                ON score (question_id, answer_id);
        ');
        $this->db->exec('
            CREATE UNIQUE INDEX score_answer_question
                ON score (answer_id, question_id);
        ');
    }
}
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

    public function getDB()
    {
        return $this->db;
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
                content TEXT NOT NULL,
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
                content TEXT NOT NULL,
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
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
                answer_id INT NOT NULL PRIMARY KEY,
                sentence TEXT NOT NULL,
                equal_to INT NULL,
                deleted BOOLEAN NOT NULL
            );
        ');
    }

    public function installQuestionTable()
    {
        $this->db->exec('
            CREATE TABLE question(
                question_id INT NOT NULL PRIMARY KEY,
                sentence TEXT NOT NULL,
                equal_to INT NULL,
                deleted BOOLEAN NOT NULL
            );
        ');
    }

    public function installScoreTable()
    {
        $this->db->exec('
            CREATE TABLE score(
                question_id INT NOT NULL,
                answer_id INT NOT NULL,
                score INT NOT NULL
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
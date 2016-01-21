<?php
namespace MifuminLib\Mifuminator;

class Installer {
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function install()
    {
        $this->installTargetTable();
        $this->installQuestionTable();
        $this->installScoreTable();
        $this->installGameStateTable();
        $this->installUserBlackListTable();
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

    public function installUserBlackListTable()
    {
        $this->db->exec('
            CREATE TABLE user_black_list(
                user_id TEXT PRIMARY KEY,
                create_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
        ');
    }
}
<?php
namespace MifuminLib\Mifuminator;

class Mifuminator {
    private $db_file_path;

    private $db = null;
    private $da = null;
    private $analyzer = null;
    private $option = null;
    private $installer = null;
    private $logic = null;
    private $game = null;

    const ANSWER_YES = 1;
    const ANSWER_NO = 2;
    const ANSWER_DONT_KNOW = 3;
    const ANSWER_PROBABLY = 4;
    const ANSWER_PROBABLY_NOT = 5;

    const STATE_ASK = 1;
    const STATE_SUGGEST = 2;
    const STATE_CORRECT = 3;
    const STATE_WRONG = 4;
    const STATE_SELECT = 5;
    const STATE_SEARCH = 6;
    const STATE_YOUWIN = 7;
    const STATE_SELECT_QUESTION = 8;
    const STATE_LEARN = 9;

    public function __construct($db_file_path, $log_dir)
    {
        $this->db_file_path = $db_file_path;
        $this->getOption()->log_dir = $log_dir;
    }

    protected function createAnalyzer()
    {
        return new Analyzer($this->getDA());
    }

    protected function createDA()
    {
        return new DataAccess($this->getDB(), $this->getOption());
    }

    protected function createDB()
    {
        return new Database($this->db_file_path);
    }

    protected function createGame()
    {
        return new Game($this->getDA(), $this->getLogic(), $this->getOption());
    }

    protected function createInstaller()
    {
        return new Installer($this->getDB());
    }

    protected function createLogic()
    {
        return new Logic($this->getDB(), $this->getOption());
    }

    protected function createOption()
    {
        return new Option();
    }

    public function getAnalyzer()
    {
        if (!isset($this->analyzer)) {
            $this->analyzer = $this->createAnalyzer();
        }
        return $this->analyzer;
    }

    public function getDA()
    {
        if (!isset($this->da)) {
            $this->da = $this->createDA();
        }
        return $this->da;
    }

    public function getDB()
    {
        if (!isset($this->db)) {
            $this->db = $this->createDB();
        }
        return $this->db;
    }

    public function getInstaller()
    {
        if (!isset($this->installer)) {
            $this->installer = $this->createInstaller();
        }
        return $this->installer;
    }

    public function getGame()
    {
        if (!isset($this->game)) {
            $this->game = $this->createGame();
        }
        return $this->game;
    }

    public function getLogic()
    {
        if (!isset($this->logic)) {
            $this->logic = $this->createLogic();
        }
        return $this->logic;
    }

    public function getOption()
    {
        if (!isset($this->option)) {
            $this->option = $this->createOption();
        }
        return $this->option;
    }
}

<?php

namespace Mifumi323\Mifuminator;

/**
 * ゲーム本体の管理・制御を行うメインクラス。
 * 各種コンポーネントの生成や状態管理を担当する。
 */
class Mifuminator
{
    /**
     * @var string データベースファイルパス
     */
    private $db_file_path;
    /**
     * @var Database|null データベースオブジェクト
     */
    private $db = null;
    /**
     * @var DataAccess|null データアクセスオブジェクト
     */
    private $da = null;
    /**
     * @var Analyzer|null 解析オブジェクト
     */
    private $analyzer = null;
    /**
     * @var Option|null オプション設定オブジェクト
     */
    private $option = null;
    /**
     * @var Installer|null インストーラーオブジェクト
     */
    private $installer = null;
    /**
     * @var Logic|null ロジックオブジェクト
     */
    private $logic = null;
    /**
     * @var Game|null ゲームオブジェクト
     */
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

    /**
     * @return Analyzer
     */
    public function getAnalyzer()
    {
        if (!isset($this->analyzer)) {
            $this->analyzer = $this->createAnalyzer();
        }

        return $this->analyzer;
    }

    /**
     * @return DataAccess
     */
    public function getDA()
    {
        if (!isset($this->da)) {
            $this->da = $this->createDA();
        }

        return $this->da;
    }

    /**
     * @return Database
     */
    public function getDB()
    {
        if (!isset($this->db)) {
            $this->db = $this->createDB();
        }

        return $this->db;
    }

    /**
     * @return Installer
     */
    public function getInstaller()
    {
        if (!isset($this->installer)) {
            $this->installer = $this->createInstaller();
        }

        return $this->installer;
    }

    /**
     * @return Game
     */
    public function getGame()
    {
        if (!isset($this->game)) {
            $this->game = $this->createGame();
        }

        return $this->game;
    }

    /**
     * @return Logic
     */
    public function getLogic()
    {
        if (!isset($this->logic)) {
            $this->logic = $this->createLogic();
        }

        return $this->logic;
    }

    /**
     * @return Option
     */
    public function getOption()
    {
        if (!isset($this->option)) {
            $this->option = $this->createOption();
        }

        return $this->option;
    }
}

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
}
<?php
namespace MifuminLib\Mifuminator;

class DataAccess {
    private $db;
    private $option;

    public function __construct($db, $option)
    {
        $this->db = $db;
        $this->option = $option;
    }

    public function addTarget($target, $user_id = null)
    {
        $this->db->insert('target', ['content' => $target, 'create_user_id' => $user_id, 'update_user_id' => $user_id]);
    }

    public function addQuestion($question, $user_id = null)
    {
        $this->db->insert('question', ['content' => $question, 'create_user_id' => $user_id, 'update_user_id' => $user_id]);
    }

    public function getDB()
    {
        return $this->db;
    }

    public function getLogFileName($time=null)
    {
        if ($time===null) $time = time();
        return $this->getLogFileNameByName(date('Ymd', $time));
    }

    public function getLogFileNameByName($name)
    {
        return $this->option->log_dir.$name.'.log';
    }

    public function getOption()
    {
        return $this->option;
    }

    public function setScore($question_id, $target_id, $score, $replace=true)
    {
        $this->db->insert('score', ['question_id' => $question_id, 'target_id' => $target_id, 'score' => $score], [], $replace, false);
    }

    public function setUserStatistics($user_id, $answer_count, $correlation)
    {
        $values = [
            ':user_id' => $user_id,
            ':answer_count' => $answer_count,
            ':correlation' => $correlation,
        ];
        $sql = '
            INSERT OR REPLACE
            INTO user_statistics
            (user_id, answer_count, correlation, update_date)
            VALUES (:user_id, :answer_count, :correlation, CURRENT_TIMESTAMP)
        ';
        $this->db->exec($sql, $values);
    }

    public function writeLog($user_id, $game_id, $target_id, $question_answer_list, $time=null, $insertToDB=false)
    {
        if ($time===null) $time = time();
        $line = date('c', time()).','.$user_id.','.$game_id.','.$target_id;
        if ($insertToDB) $this->db->begin();
        foreach ($question_answer_list as $question_id => $answer) {
            $line .= ','.$question_id.'='.$answer;
            if ($insertToDB) $this->setScore($question_id, $target_id, (int)(0.5 * $this->option->score[$answer] * $this->option->score_max / $this->option->score[Mifuminator::ANSWER_YES]), false);
        }
        if ($insertToDB) $this->db->commit();
        file_put_contents($this->getLogFileName($time), $line."\n", FILE_APPEND);
    }
}

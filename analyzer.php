<?php
namespace MifuminLib\Mifuminator;

class Analyzer {
    private $da;
    private $db;
    private $option;

    public function __construct($da)
    {
        $this->da = $da;
        $this->db = $da->getDB();
        $this->option = $da->getOption();
    }

    public function analyze()
    {
        // 読み替えデータ
        $question_alias = [];
        $ret = $this->db->query('SELECT question_id from_id, CASE WHEN deleted<>0 THEN 0 WHEN equal_to IS NOT NULL THEN equal_to ELSE question_id END to_id FROM question WHERE deleted<>0 OR equal_to IS NOT NULL;');
        foreach ($ret as $row) {
            $question_alias[$row['from_id']] = $row['to_id'];
        }
        $target_alias = [];
        $ret = $this->db->query('SELECT target_id from_id, CASE WHEN deleted<>0 THEN 0 WHEN equal_to IS NOT NULL THEN equal_to ELSE target_id END to_id FROM target WHERE deleted<>0 OR equal_to IS NOT NULL;');
        foreach ($ret as $row) {
            $target_alias[$row['from_id']] = $row['to_id'];
        }

        // ファイルごとの統計
        $count = array();
        $total_score = array();
        foreach (glob($this->da->getLogFileNameByName('*')) as $file) {
            if (basename($file)==$this->da->getLogFileName()) continue;
            $this->analyzeFile($file, $count, $total_score, $question_alias, $target_alias);
        }

        // ユーザーごとに平均を取って、1ユーザーの重みは何回やっても1回分
        $score_power = $this->option->score_max / $this->option->score[Mifuminator::ANSWER_YES];
        $this->db->begin();
        foreach ($total_score as $target_id => $score_t) {
            foreach ($score_t as $question_id => $score_q) {
                $count_value = count($score_q);
                $score_value = 0;
                foreach ($score_q as $user_id => $score_u) {
                    $score_value += $score_u / $count[$target_id][$question_id][$user_id];
                }
                $average_score = $score_value / $count_value;
                $population_power = 1/(1+exp(-$this->option->logistic_regression_param*($count_value-1)));
                $final_score = (int)($average_score * $population_power * $score_power);
                $this->da->setScore($question_id, $target_id, $final_score);
            }
        }
        $this->db->exec('DELETE FROM game_state WHERE create_date < DATETIME(\'now\', \'-1 day\');');
        $this->db->exec('
            DELETE FROM score
            WHERE NOT EXISTS (SELECT * FROM target WHERE target.target_id = score.target_id AND deleted = 0 AND equal_to IS NULL)
            OR NOT EXISTS (SELECT * FROM question WHERE question.question_id = score.question_id AND deleted = 0 AND equal_to IS NULL);
        ');
        $this->db->commit();
        $this->db->exec('VACUUM;');
        return TRUE;
    }

    public function analyzeFile($file, &$count, &$total_score, $question_alias=[], $target_alias=[])
    {
        $handle = fopen($file, 'r');
        while (($array = fgetcsv($handle)) !== FALSE) {
            if (count($array)<5) break;
            $timestamp = $array[0];
            $user_id = $array[1];
            $game_id = $array[2];
            $target_id = $array[3];
            if (isset($target_alias[$target_id])) $target_id = $target_alias[$target_id];
            if ($target_id<=0) continue;
            for ($i=4; $i<count($array); $i++) {
                list($question_id, $answer) = explode('=', $array[$i]);
                if (isset($question_alias[$question_id])) $question_id = $question_alias[$question_id];
                if ($question_id<=0) continue;
                $count[$target_id][$question_id][$user_id]++;
                $total_score[$target_id][$question_id][$user_id] += $this->option->score[trim($answer)];
            }
        }
        fclose($handle);
    }
}
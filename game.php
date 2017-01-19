<?php
namespace MifuminLib\Mifuminator;

class GameInvalidTargetException extends \Exception {
    public function __construct($message=null, $code=0, Exception $previous=null)
    {
        parent::__construct($message, $code, $previous);
    }
}

class Game {
    private $da;
    private $db;
    private $logic;
    private $option;

    public function __construct($da, $logic, $option)
    {
        $this->da = $da;
        $this->db = $da->getDB();
        $this->logic = $logic;
        $this->option = $option;
    }

    public function answer($game_state, $answer)
    {
        $cutoff_difference = $this->getGameOption($game_state, 'cutoff_difference');
        $use_final_learning = $this->getGameOption($game_state, 'use_final_learning');
        $suggest_timings = $this->getGameOption($game_state, 'suggest_timings');
        $avoid_same_answer_number = $this->getGameOption($game_state, 'avoid_same_answer_number');
        $try_unknown_question_rate = $this->getGameOption($game_state, 'try_unknown_question_rate');
        $score = $this->getGameOption($game_state, 'score');

        $game_state['question_answer_history'][$game_state['question']['question_id']] = $answer;
        $game_state['targets'] = $this->getLogic()->guessTarget($game_state['question_answer_history'], 100, 10, $game_state['except_target_ids'], $cutoff_difference, $score);
        $game_state['best_target_ids'] = $this->getLogic()->getBestTargetIDs($game_state['targets'], 100, 0, $cutoff_difference);

        if (count($game_state['best_target_ids'])==1 || in_array($game_state['stage_number'], $suggest_timings)) {
            if (!$use_final_learning || $game_state['asked_unknown_question'] || $game_state['stage_number']>=$suggest_timings[0]) {
                $game_state['question'] = null;
                $game_state['state'] = Mifuminator::STATE_SUGGEST;
            }else {
                $game_state['asked_unknown_question'] = true;
                $game_state['question'] = $this->getLogic()->nextQuestion($game_state['question_answer_history'], $game_state['best_target_ids'], 'getQuestionScoreSqlUnknown', $avoid_same_answer_number, $try_unknown_question_rate);
                $game_state['stage_number']++;
                $game_state['state'] = Mifuminator::STATE_ASK;
            }
        }else {
            $game_state['question'] = $this->getLogic()->nextQuestion($game_state['question_answer_history'], $game_state['best_target_ids'], null, $avoid_same_answer_number, $try_unknown_question_rate);
            if ($game_state['question']['function']=='getQuestionScoreSqlUnknown') {
                $game_state['asked_unknown_question'] = true;
            }
            $game_state['stage_number']++;
            $game_state['state'] = Mifuminator::STATE_ASK;
        }

        return $this->nextGameState($game_state);
    }

    public function checkAnswer($game_state, $correct)
    {
        $suggest_timings = $this->getGameOption($game_state, 'suggest_timings');
        if ($correct) {
            $game_state['final_target'] = $game_state['targets'][0];
            $this->getDA()->writeLog($game_state['user_id'], $game_state['game_id'], $game_state['final_target']['target_id'], $game_state['question_answer_history'], null, true);
            $game_state['result_state'] = $state = Mifuminator::STATE_CORRECT;
            $delete_history = true;
        }else {
            if ($game_state['stage_number']<max($suggest_timings)) {
                $game_state['except_target_ids'][] = $game_state['targets'][0]['target_id'];
                $state = Mifuminator::STATE_WRONG;
                $delete_history = false;
            }else {
                return $this->selectTarget($game_state);
            }
        }

        return $this->nextGameState($game_state, $state, [], $delete_history);
    }

    public function continueGame($game_state)
    {
        $cutoff_difference = $this->getGameOption($game_state, 'cutoff_difference');
        $avoid_same_answer_number = $this->getGameOption($game_state, 'avoid_same_answer_number');
        $try_unknown_question_rate = $this->getGameOption($game_state, 'try_unknown_question_rate');
        $score = $this->getGameOption($game_state, 'score');

        $game_state['targets'] = $this->getLogic()->guessTarget($game_state['question_answer_history'], 100, 10, $game_state['except_target_ids'], $cutoff_difference, $score);
        $game_state['best_target_ids'] = $this->getLogic()->getBestTargetIDs($game_state['targets'], 100, 0, $cutoff_difference);
        $game_state['question'] = $this->getLogic()->nextQuestion($game_state['question_answer_history'], $game_state['best_target_ids'], null, $avoid_same_answer_number, $try_unknown_question_rate);
        $game_state['stage_number']++;

        return $this->nextGameState($game_state, Mifuminator::STATE_ASK);
    }

    public function deleteGameState($game_id)
    {
        $statement = $this->getDB()->prepare('DELETE FROM game_state WHERE game_id = :game_id;');
        return $statement->execute(['game_id' => $game_id]);
    }

    public function generateGameID($user_id)
    {
        return md5($user_id.microtime());
    }

    public function generateStageID($user_id, $game_id)
    {
        return md5($user_id.$game_id.microtime());
    }

    public function getDA()
    {
        return $this->da;
    }

    public function getDB()
    {
        return $this->db;
    }

    public function getGameOption($game_state, $key)
    {
        return $game_state['option'][$key]!==null?$game_state['option'][$key]:$this->getOption()->$key;
    }

    public function getGameState($user_id, $stage_id)
    {
        $statement = $this->getDB()->exec('SELECT data FROM game_state WHERE user_id = :user_id AND stage_id = :stage_id;', ['user_id'=>$user_id,'stage_id'=>$stage_id]);
        $data = $statement->fetchColumn();
        return $data?unserialize($data):null;
    }

    public function getLogic()
    {
        return $this->logic;
    }

    public function getOption()
    {
        return $this->option;
    }

    public function learn($game_state, $answers)
    {
        $score = $this->getGameOption($game_state, 'score');

        $question_id = $game_state['question']['question_id'];
        $user_id = $game_state['user_id'];
        $game_id = $game_state['game_id'];
        foreach ($answers as $target_id => $answer) {
            if (in_array($answer, array_keys($score))) {
                $this->getDA()->writeLog($user_id, $game_id, $target_id, [$question_id => $answer], null, true);
            }
        }
        return $this->nextGameState($game_state, $game_state['result_state'], [], true);
    }

    public function learningStage($game_state, $question_id, $delete_history=false)
    {
        $learn_target_max = $this->getGameOption($game_state, 'learn_target_max');
        $learn_target_unknown = $this->getGameOption($game_state, 'learn_target_unknown');
        $score = $this->getGameOption($game_state, 'score');
        $question_additional_column = $this->getOption()->question_additional_column;
        if (strlen($question_additional_column)>0) $question_additional_column = ','.$question_additional_column;
        $target_additional_column = $this->getOption()->target_additional_column;
        if (strlen($target_additional_column)>0) $target_additional_column = ','.$target_additional_column;

        $ret = $this->getDB()->query('
            SELECT
                question_id
                , content
                '.$question_additional_column.'
            FROM question
            WHERE question_id = :question_id
            LIMIT 1;
        ', ['question_id' => $question_id]);
        $question = $ret[0];
        $game_state['question'] = $question;
        $question_answer_history = $game_state['question_answer_history'];

        $learn_targets = [];
        if (isset($game_state['final_target'])) $learn_targets[] = $game_state['final_target'];

        $limit = $learn_target_max - count($learn_targets) - $learn_target_unknown;
        if ($limit>0) {
            $except_sql = '';
            if (count($learn_targets)>0) {
                foreach ($learn_targets as $target) {
                    if (strlen($except_sql)>0) $except_sql .= ',';
                    $except_sql .= (int)$target['target_id'];
                }
                $except_sql = 'AND target_id NOT IN ('.$except_sql.')';
            }
            $targets = $this->getDB()->query('
                SELECT
                    target_id
                    , content
                    , (
                        SELECT -COUNT(*)
                        FROM score
                        WHERE score.target_id = target.target_id
                        AND score.question_id = :question_id
                    ) score
                    , '.$this->getLogic()->getTargetScoreSql($question_answer_history, $score).' score2
                    , (
                        SELECT -COUNT(*)
                        FROM score
                        WHERE score.target_id = target.target_id
                    ) score3
                    '.$target_additional_column.'
                FROM target
                WHERE deleted = 0
                AND equal_to IS NULL
                '.$except_sql.'
                ORDER BY score DESC, score2 DESC, score3 DESC, RANDOM()
                LIMIT '.$limit.';
            ', ['question_id' => $question_id]);
            $learn_targets = array_merge($learn_targets, $targets);
        }

        $limit = $learn_target_max - count($learn_targets);
        if ($limit>0) {
            $except_sql = '';
            if (count($learn_targets)>0) {
                foreach ($learn_targets as $target) {
                    if (strlen($except_sql)>0) $except_sql .= ',';
                    $except_sql .= (int)$target['target_id'];
                }
                $except_sql = 'AND target_id NOT IN ('.$except_sql.')';
            }
            $targets = $this->getDB()->query('
                SELECT
                    target_id
                    , content
                    , (
                        SELECT -COUNT(*)
                        FROM score
                        WHERE score.target_id = target.target_id
                        AND score.question_id = :question_id
                    ) score
                    , (
                        SELECT -COUNT(*)
                        FROM score
                        WHERE score.target_id = target.target_id
                    ) score2
                    '.$target_additional_column.'
                FROM target
                WHERE deleted = 0
                AND equal_to IS NULL
                '.$except_sql.'
                ORDER BY score DESC, score2 DESC, RANDOM()
                LIMIT '.$limit.';
            ', ['question_id' => $question_id]);
            $learn_targets = array_merge($learn_targets, $targets);
        }

        $game_state['targets'] = $learn_targets;

        return $this->nextGameState($game_state, Mifuminator::STATE_LEARN, [
            'learn',
        ], $delete_history);
    }

    public function nextGameState($game_state, $state=null, $allowed_method=[], $delete_history=false)
    {
        if ($state) $game_state['state'] = $state;
        $game_state['previous_stage_id'] = $delete_history ? null : $game_state['stage_id'];
        $game_state['stage_id'] = $this->generateStageID($game_state['user_id'], $game_state['game_id']);
        $game_state['allowed_method'] = $allowed_method;
        $this->getDB()->begin();
        if ($delete_history) $this->deleteGameState($game_state['game_id']);
        $this->setGameState($game_state);
        $this->getDB()->commit();
        return $game_state;
    }

    public function newQuestion($game_state, $question)
    {
        $this->getDB()->begin();
        $this->getDA()->addQuestion($question, $game_state['user_id']);
        $ret = $this->getDB()->exec('SELECT MAX(question_id) FROM question;');
        $question_id = $ret->fetchColumn();
        $this->getDB()->commit();

        return $this->learningStage($game_state, $question_id, true);
    }

    public function newTarget($game_state, $target)
    {
        $this->getDB()->begin();
        $this->getDA()->addTarget($target, $game_state['user_id']);
        $ret = $this->getDB()->query('SELECT * FROM target ORDER BY target_id DESC LIMIT 1;');
        $game_state['targets'] = $ret;
        $game_state['final_target'] = $ret[0];
        $this->getDB()->commit();

        $target_id = $game_state['targets'][0]['target_id'];
        $this->getDA()->writeLog($game_state['user_id'], $game_state['game_id'], $target_id, $game_state['question_answer_history'], null, true);

        $game_state['result_state'] = Mifuminator::STATE_YOUWIN;
        return $this->nextGameState($game_state, $game_state['result_state'], [], true);
    }

    public function searchQuestion($game_state, $search)
    {
        $question_additional_column = $this->getOption()->question_additional_column;
        if (strlen($question_additional_column)>0) $question_additional_column = ','.$question_additional_column;
        $params = ['content' => '%'.$search.'%'];
        $statement = $this->getDB()->prepare('
            SELECT
                question_id,
                content,
                '.$this->getLogic()->getQuestionScoreSqlUnknown($game_state['question_answer_history'], [$game_state['targets'][0]['target_id']]).' score
                '.$question_additional_column.'
            FROM question
            WHERE deleted = 0
            AND equal_to IS NULL
            AND content LIKE :content
            ORDER BY score DESC, content
            LIMIT 10
        ');
        $statement->execute($params);
        $game_state['questions'] = $statement->fetchAll();
        $game_state['search'] = $search;

        return $this->nextGameState($game_state, Mifuminator::STATE_SELECT_QUESTION, [
            'newQuestion',
            'searchQuestion',
            'selectQuestion',
        ]);
    }

    public function searchTarget($game_state, $search)
    {
        $target_additional_column = $this->getOption()->target_additional_column;
        if (strlen($target_additional_column)>0) $target_additional_column = ','.$target_additional_column;
        $params = ['content' => '%'.$search.'%'];
        $statement = $this->getDB()->prepare('
            SELECT
                target_id,
                content
                '.$target_additional_column.'
            FROM target
            WHERE deleted = 0
            AND equal_to IS NULL
            AND (
                content LIKE :content
                OR EXISTS (
                    SELECT 1
                    FROM target subtarget
                    WHERE subtarget.deleted = 0
                    AND subtarget.equal_to = target.target_id
                    AND subtarget.content LIKE :content
                )
            )
            ORDER BY content
            LIMIT 10
        ');
        $statement->execute($params);
        $game_state['targets'] = $statement->fetchAll();
        $game_state['search'] = $search;

        return $this->nextGameState($game_state, Mifuminator::STATE_SELECT, [
            'newTarget',
            'searchTarget',
            'selectTarget',
        ]);
    }

    public function selectTarget($game_state)
    {
        $cutoff_difference = $this->getGameOption($game_state, 'cutoff_difference');
        $score = $this->getGameOption($game_state, 'score');

        $game_state['targets'] = $this->getLogic()->guessTarget($game_state['question_answer_history'], 10, 10, [], $cutoff_difference, $score);

        return $this->nextGameState($game_state, Mifuminator::STATE_SELECT);
    }

    public function setGameState($game_state)
    {
        $params = [
            'user_id' => $game_state['user_id'],
            'game_id' => $game_state['game_id'],
            'stage_id' => $game_state['stage_id'],
            'data' => serialize($game_state),
        ];
        $this->getDB()->insert('game_state', $params);
    }

    public function startGame($user_id, $options=[])
    {
        $game_state = [];
        $game_state['option'] = $options;

        $avoid_same_answer_number = $this->getGameOption($game_state, 'avoid_same_answer_number');
        $try_unknown_question_rate = $this->getGameOption($game_state, 'try_unknown_question_rate');

        $game_id = $this->generateGameID($user_id);
        $game_state['user_id'] = $user_id;
        $game_state['game_id'] = $game_id;
        $game_state['targets'] = $this->getLogic()->guessTarget([], 100, 100, [], 0, []);
        $game_state['best_target_ids'] = $this->getLogic()->getBestTargetIDs($game_state['targets'], 100, 100, 0);
        $game_state['question'] = $this->getLogic()->nextQuestion([], $game_state['best_target_ids'], null, $avoid_same_answer_number, $try_unknown_question_rate);
        $game_state['stage_number'] = 1;
        $game_state['question_answer_history'] = [];
        $game_state['except_targets'] = [];
        return $this->nextGameState($game_state, Mifuminator::STATE_ASK);
    }

    public function teach($game_state, $target_id)
    {
        if (!in_array($target_id, array_map(function($target) { return $target['target_id']; }, $game_state['targets']))) {
            throw new GameInvalidTargetException();
        }
        $target_additional_column = $this->getOption()->target_additional_column;
        if (strlen($target_additional_column)>0) $target_additional_column = ','.$target_additional_column;
        $params = ['target_id' => $target_id];
        $ret = $this->getDB()->query('
            SELECT
                target_id,
                content
                '.$target_additional_column.'
            FROM target
            WHERE target_id = :target_id
        ', $params);
        $game_state['final_target'] = $ret[0];
        $this->getDA()->writeLog($game_state['user_id'], $game_state['game_id'], $game_state['final_target']['target_id'], $game_state['question_answer_history'], null, true);
        $game_state['result_state'] = Mifuminator::STATE_YOUWIN;
        return $this->nextGameState($game_state, Mifuminator::STATE_YOUWIN, [], true);
    }
}

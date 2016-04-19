<?php
namespace MifuminLib\Mifuminator;

class Option {
    // ログファイルのありか
    public $log_dir;

    // 回答内容ごとのスコア
    public $score = [
        Mifuminator::ANSWER_YES          => 10,
        Mifuminator::ANSWER_NO           => -10,
        Mifuminator::ANSWER_DONT_KNOW    => 0,
        Mifuminator::ANSWER_PROBABLY     => 1,
        Mifuminator::ANSWER_PROBABLY_NOT => -1,
    ];

    // 統計スコアの最大値
    public $score_max = 100;

    // 候補のうちトップからこれだけ引き離されていると候補から除外
    public $cutoff_difference = 2000;

    // 未知の質問を投げかける確率(%)
    public $try_unknown_question_rate = 5;

    // ロジスティック回帰の計数
    public $logistic_regression_param = 0.054;

    // 何回目で答えを言うか
    public $suggest_timings = [20, 40, 50];

    // 最後の1問を学習優先で選ぶ機能を使うか
    public $use_final_learning = TRUE;

    // この回数だけ連続で同じ回答をした場合に、今までと違う回答を期待できる質問を探す(0で無効)
    public $avoid_same_answer_number = 4;

    // 質問指定の学習時に提示する候補数
    public $learn_target_max = 10;

    // 質問指定の学習時に提示する候補に交ぜる学習データ少な目のデータ数
    public $learn_target_unknown = 1;

    // 質問の追加項目(コンマ区切りのSQL文。SELECT文に直接挿入されます)
    public $question_additional_column = NULL;

    // 対象の追加項目(コンマ区切りのSQL文。SELECT文に直接挿入されます)
    public $target_additional_column = NULL;
}
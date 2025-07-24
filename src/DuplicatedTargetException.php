<?php

namespace Mifumi323\Mifuminator;

/**
 * ターゲットの重複に関する例外クラス。
 * ターゲットが既に存在する場合にスローされる。
 */
class DuplicatedTargetException extends \Exception
{
    /**
     * @param string|null $message エラーメッセージ
     * @param int $code エラーコード
     * @param \Exception|null $previous 前の例外
     */
    public function __construct($message = null, $code = 0, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

<?php

declare(strict_types=1);

class TestParsedown extends Parsedown
{
    public function getTextLevelElements()
    {
        return self::$textLevelElements;
    }
}

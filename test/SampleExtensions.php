<?php

declare(strict_types=1);

class UnsafeExtension extends Parsedown
{
    protected function blockFencedCodeComplete($block)
    {
        $text = $block['element']['element']['text'];
        unset($block['element']['element']['text']);

        // WARNING: There is almost always a better way of doing things!
        //
        // This example is one of them, unsafe behaviour is NOT needed here.
        // Only use this if you trust the input and have no idea what
        // the output HTML will look like (e.g. using an external parser).
        $block['element']['element']['rawHtml'] = "<p>$text</p>";

        return $block;
    }
}


class TrustDelegatedExtension extends Parsedown
{
    protected function blockFencedCodeComplete($block)
    {
        $text = $block['element']['element']['text'];
        unset($block['element']['element']['text']);

        // WARNING: There is almost always a better way of doing things!
        //
        // This behaviour is NOT needed in the demonstrated case.
        // Only use this if you are sure that the result being added into
        // rawHtml is safe.
        // (e.g. using an external parser with escaping capabilities).
        $block['element']['element']['rawHtml']                = "<p>$text</p>";
        $block['element']['element']['allowRawHtmlInSafeMode'] = true;

        return $block;
    }
}

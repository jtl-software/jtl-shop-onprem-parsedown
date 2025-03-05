<?php

declare(strict_types=1);

class Parsedown
{
    public const VERSION = '2.0.0';

    protected bool $breaksEnabled = false;

    protected bool $markupEscaped = false;

    protected bool $urlsLinked = true;

    protected bool $safeMode = false;

    protected bool $strictMode = false;

    protected static array $safeLinksWhitelist = [
        'http://',
        'https://',
        'ftp://',
        'ftps://',
        'mailto:',
        'tel:',
        'data:image/png;base64,',
        'data:image/gif;base64,',
        'data:image/jpeg;base64,',
        'irc:',
        'ircs:',
        'git:',
        'ssh:',
        'news:',
        'steam:',
    ];

    protected static array $blockTypes = [
        '#' => ['Header'],
        '*' => ['Rule', 'List'],
        '+' => ['List'],
        '-' => ['SetextHeader', 'Table', 'Rule', 'List'],
        '0' => ['List'],
        '1' => ['List'],
        '2' => ['List'],
        '3' => ['List'],
        '4' => ['List'],
        '5' => ['List'],
        '6' => ['List'],
        '7' => ['List'],
        '8' => ['List'],
        '9' => ['List'],
        ':' => ['Table'],
        '<' => ['Comment', 'Markup'],
        '=' => ['SetextHeader'],
        '>' => ['Quote'],
        '[' => ['Reference'],
        '_' => ['Rule'],
        '`' => ['FencedCode'],
        '|' => ['Table'],
        '~' => ['FencedCode'],
    ];

    protected static array $inlineTypes = [
        '!'  => ['Image'],
        '&'  => ['SpecialCharacter'],
        '*'  => ['Emphasis'],
        ':'  => ['Url'],
        '<'  => ['UrlTag', 'EmailTag', 'Markup'],
        '['  => ['Link'],
        '_'  => ['Emphasis'],
        '`'  => ['Code'],
        '~'  => ['Strikethrough'],
        '\\' => ['EscapeSequence'],
    ];

    protected array $unmarkedBlockTypes = [
        'Code',
    ];

    protected string $inlineMarkerList = '!*_&[:<`~\\';

    private static array $instances = [];

    protected array $definitionData = [];

    protected static array $specialCharacters = [
        '\\',
        '`',
        '*',
        '_',
        '{',
        '}',
        '[',
        ']',
        '(',
        ')',
        '>',
        '#',
        '+',
        '-',
        '.',
        '!',
        '|',
        '~'
    ];

    protected array $StrongRegex = [
        '*' => '/^[*]{2}((?:\\\\\*|[^*]|[*][^*]*+[*])+?)[*]{2}(?![*])/s',
        '_' => '/^__((?:\\\\_|[^_]|_[^_]*+_)+?)__(?!_)/us',
    ];

    protected array $EmRegex = [
        '*' => '/^[*]((?:\\\\\*|[^*]|[*][*][^*]+?[*][*])+?)[*](?![*])/s',
        '_' => '/^_((?:\\\\_|[^_]|__[^_]*__)+?)_(?!_)\b/us',
    ];

    protected string $regexHtmlAttribute = '[a-zA-Z_:][\w:.-]*+(?:\s*+=\s*+(?:[^"\'=<>`\s]+|"[^"]*+"|\'[^\']*+\'))?+';

    protected static array $textLevelElements = [
        'a',
        'br',
        'bdo',
        'abbr',
        'blink',
        'nextid',
        'acronym',
        'basefont',
        'b',
        'em',
        'big',
        'cite',
        'small',
        'spacer',
        'listing',
        'i',
        'rp',
        'del',
        'code',
        'strike',
        'marquee',
        'q',
        'rt',
        'ins',
        'font',
        'strong',
        's',
        'tt',
        'kbd',
        'mark',
        'u',
        'xm',
        'sub',
        'nobr',
        'sup',
        'ruby',
        'var',
        'span',
        'wbr',
        'time',
    ];

    public function text(string $text): string
    {
        $elements = $this->textElements($text);
        $markup   = $this->elements($elements);
        return trim($markup, "\n");
    }

    protected function textElements(string $text): array
    {
        // make sure no definitions are set
        $this->definitionData = [];

        // standardize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // remove surrounding line breaks
        $text = trim($text, "\n");

        // split text into lines
        $lines = explode("\n", $text);

        // iterate through lines to identify blocks
        return $this->linesElements($lines);
    }

    public function setBreaksEnabled(bool $breaksEnabled): self
    {
        $this->breaksEnabled = $breaksEnabled;

        return $this;
    }

    public function setMarkupEscaped(bool $markupEscaped): self
    {
        $this->markupEscaped = $markupEscaped;

        return $this;
    }

    public function setUrlsLinked(bool $urlsLinked): self
    {
        $this->urlsLinked = $urlsLinked;

        return $this;
    }

    public function setSafeMode(bool $safeMode): self
    {
        $this->safeMode = $safeMode;

        return $this;
    }

    public function setStrictMode(bool $strictMode): self
    {
        $this->strictMode = $strictMode;

        return $this;
    }

    protected function lines(array $lines): string
    {
        return $this->elements($this->linesElements($lines));
    }

    protected function linesElements(array $lines): array
    {
        $elements     = [];
        $currentBlock = null;
        foreach ($lines as $line) {
            if (rtrim($line) === '') {
                if (isset($currentBlock)) {
                    $currentBlock['interrupted'] = (isset($currentBlock['interrupted'])
                        ? $currentBlock['interrupted'] + 1 : 1
                    );
                }
                continue;
            }
            while (($beforeTab = strstr($line, "\t", true)) !== false) {
                $shortage = 4 - mb_strlen($beforeTab, 'utf-8') % 4;

                $line = $beforeTab
                    . str_repeat(' ', $shortage)
                    . substr($line, strlen($beforeTab) + 1);
            }
            $indent   = strspn($line, ' ');
            $text     = $indent > 0 ? substr($line, $indent) : $line;
            $lineData = ['body' => $line, 'indent' => $indent, 'text' => $text];

            if (isset($currentBlock['continuable'])) {
                $methodName = 'block' . $currentBlock['type'] . 'Continue';
                $block      = $this->$methodName($lineData, $currentBlock);
                if (isset($block)) {
                    $currentBlock = $block;

                    continue;
                }

                if ($this->isBlockCompletable($currentBlock['type'])) {
                    $methodName   = 'block' . $currentBlock['type'] . 'Complete';
                    $currentBlock = $this->$methodName($currentBlock);
                }
            }

            $marker     = $text[0];
            $blockTypes = $this->unmarkedBlockTypes;
            if (isset(self::$blockTypes[$marker])) {
                foreach (self::$blockTypes[$marker] as $blockType) {
                    $blockTypes [] = $blockType;
                }
            }
            foreach ($blockTypes as $blockType) {
                $block = $this->{"block$blockType"}($lineData, $currentBlock);
                if (isset($block)) {
                    $block['type'] = $blockType;
                    if (!isset($block['identified'])) {
                        if (isset($currentBlock)) {
                            $elements[] = $this->extractElement($currentBlock);
                        }
                        $block['identified'] = true;
                    }
                    if ($this->isBlockContinuable($blockType)) {
                        $block['continuable'] = true;
                    }
                    $currentBlock = $block;
                    continue 2;
                }
            }
            if (isset($currentBlock) && $currentBlock['type'] === 'Paragraph') {
                $block = $this->paragraphContinue($lineData, $currentBlock);
            }
            if (isset($block)) {
                $currentBlock = $block;
            } else {
                if (isset($currentBlock)) {
                    $elements[] = $this->extractElement($currentBlock);
                }
                $currentBlock = $this->paragraph($lineData);

                $currentBlock['identified'] = true;
            }
        }
        if (isset($currentBlock['continuable']) && $this->isBlockCompletable($currentBlock['type'])) {
            $methodName   = 'block' . $currentBlock['type'] . 'Complete';
            $currentBlock = $this->$methodName($currentBlock);
        }
        if (isset($currentBlock)) {
            $elements[] = $this->extractElement($currentBlock);
        }

        return $elements;
    }

    protected function extractElement(array $Component)
    {
        if (!isset($Component['element'])) {
            if (isset($Component['markup'])) {
                $Component['element'] = ['rawHtml' => $Component['markup']];
            } elseif (isset($Component['hidden'])) {
                $Component['element'] = [];
            }
        }

        return $Component['element'];
    }

    protected function isBlockContinuable($Type): bool
    {
        return method_exists($this, 'block' . $Type . 'Continue');
    }

    protected function isBlockCompletable($Type): bool
    {
        return method_exists($this, 'block' . $Type . 'Complete');
    }

    protected function blockCode(array $line, ?array $block = null): ?array
    {
        if (isset($block) && $block['type'] === 'Paragraph' && !isset($block['interrupted'])) {
            return null;
        }
        if ($line['indent'] >= 4) {
            $text = substr($line['body'], 4);

            return [
                'element' => [
                    'name'    => 'pre',
                    'element' => [
                        'name' => 'code',
                        'text' => $text,
                    ],
                ],
            ];
        }

        return null;
    }

    protected function blockCodeContinue(array $line, array $block): ?array
    {
        if ($line['indent'] < 4) {
            return null;
        }
        if (isset($block['interrupted'])) {
            $block['element']['element']['text'] .= str_repeat("\n", $block['interrupted']);

            unset($block['interrupted']);
        }

        $block['element']['element']['text'] .= "\n";

        $text = substr($line['body'], 4);

        $block['element']['element']['text'] .= $text;

        return $block;
    }

    protected function blockCodeComplete($block)
    {
        return $block;
    }

    protected function blockComment(array $line): ?array
    {
        if ($this->markupEscaped || $this->safeMode) {
            return null;
        }
        if (str_starts_with($line['text'], '<!--')) {
            $block = [
                'element' => [
                    'rawHtml'   => $line['body'],
                    'autobreak' => true,
                ],
            ];
            if (str_contains($line['text'], '-->')) {
                $block['closed'] = true;
            }

            return $block;
        }

        return null;
    }

    protected function blockCommentContinue(array $line, array $block): ?array
    {
        if (isset($block['closed'])) {
            return null;
        }
        $block['element']['rawHtml'] .= "\n" . $line['body'];
        if (str_contains($line['text'], '-->')) {
            $block['closed'] = true;
        }

        return $block;
    }

    protected function blockFencedCode(array $line): ?array
    {
        $marker       = $line['text'][0];
        $openerLength = strspn($line['text'], $marker);
        if ($openerLength < 3) {
            return null;
        }
        $infostring = trim(substr($line['text'], $openerLength), "\t ");
        if (str_contains($infostring, '`')) {
            return null;
        }
        $element = [
            'name' => 'code',
            'text' => '',
        ];
        if ($infostring !== '') {
            /**
             * https://www.w3.org/TR/2011/WD-html5-20110525/elements.html#classes
             * Every HTML element may have a class attribute specified.
             * The attribute, if specified, must have a value that is a set
             * of space-separated tokens representing the various classes
             * that the element belongs to.
             * [...]
             * The space characters, for the purposes of this specification,
             * are U+0020 SPACE, U+0009 CHARACTER TABULATION (tab),
             * U+000A LINE FEED (LF), U+000C FORM FEED (FF), and
             * U+000D CARRIAGE RETURN (CR).
             */
            $language = substr($infostring, 0, strcspn($infostring, " \t\n\f\r"));

            $element['attributes'] = ['class' => "language-$language"];
        }

        return [
            'char'         => $marker,
            'openerLength' => $openerLength,
            'element'      => [
                'name'    => 'pre',
                'element' => $element,
            ],
        ];
    }

    protected function blockFencedCodeContinue(array $line, array $block): ?array
    {
        if (isset($block['complete'])) {
            return null;
        }
        if (isset($block['interrupted'])) {
            $block['element']['element']['text'] .= str_repeat("\n", $block['interrupted']);

            unset($block['interrupted']);
        }
        if (
            ($len = strspn($line['text'], $block['char'])) >= $block['openerLength']
            && rtrim(substr($line['text'], $len), ' ') === ''
        ) {
            $block['element']['element']['text'] = substr($block['element']['element']['text'], 1);

            $block['complete'] = true;

            return $block;
        }
        $block['element']['element']['text'] .= "\n" . $line['body'];

        return $block;
    }

    protected function blockFencedCodeComplete($block)
    {
        return $block;
    }

    protected function blockHeader(array $line): ?array
    {
        $level = strspn($line['text'], '#');
        if ($level > 6) {
            return null;
        }
        $text = trim($line['text'], '#');
        if ($this->strictMode && isset($text[0]) && $text[0] !== ' ') {
            return null;
        }
        $text = trim($text, ' ');

        return [
            'element' => [
                'name'    => 'h' . $level,
                'handler' => [
                    'function'    => 'lineElements',
                    'argument'    => $text,
                    'destination' => 'elements',
                ]
            ],
        ];
    }

    protected function blockList(array $line, ?array $currentBlock = null): ?array
    {
        [$name, $pattern] = $line['text'][0] <= '-' ? ['ul', '[*+-]'] : ['ol', '[0-9]{1,9}+[.\)]'];

        if (preg_match('/^(' . $pattern . '([ ]++|$))(.*+)/', $line['text'], $matches)) {
            $contentIndent = strlen($matches[2]);

            if ($contentIndent >= 5) {
                --$contentIndent;
                $matches[1] = substr($matches[1], 0, -$contentIndent);
                $matches[3] = str_repeat(' ', $contentIndent) . $matches[3];
            } elseif ($contentIndent === 0) {
                $matches[1] .= ' ';
            }

            $markerWithoutWhitespace = strstr($matches[1], ' ', true);

            $block                            = [
                'indent'  => $line['indent'],
                'pattern' => $pattern,
                'data'    => [
                    'type'       => $name,
                    'marker'     => $matches[1],
                    'markerType' => ($name === 'ul' ? $markerWithoutWhitespace : substr($markerWithoutWhitespace, -1)),
                ],
                'element' => [
                    'name'     => $name,
                    'elements' => [],
                ],
            ];
            $block['data']['markerTypeRegex'] = preg_quote($block['data']['markerType'], '/');

            if ($name === 'ol') {
                $listStart = ltrim(strstr($matches[1], $block['data']['markerType'], true), '0') ?: '0';

                if ($listStart !== '1') {
                    if (
                        isset($currentBlock)
                        && $currentBlock['type'] === 'Paragraph'
                        && !isset($currentBlock['interrupted'])
                    ) {
                        return null;
                    }

                    $block['element']['attributes'] = ['start' => $listStart];
                }
            }

            $block['li'] = [
                'name'    => 'li',
                'handler' => [
                    'function'    => 'li',
                    'argument'    => !empty($matches[3]) ? [$matches[3]] : [],
                    'destination' => 'elements'
                ]
            ];

            $block['element']['elements'] [] = &$block['li'];

            return $block;
        }

        return null;
    }

    protected function blockListContinue(array $line, array $block): ?array
    {
        if (isset($block['interrupted']) && empty($block['li']['handler']['argument'])) {
            return null;
        }
        $requiredIndent = ($block['indent'] + strlen($block['data']['marker']));

        if (
            $line['indent'] < $requiredIndent
            && (
                (
                    $block['data']['type'] === 'ol'
                    && preg_match(
                        '/^[0-9]++' . $block['data']['markerTypeRegex'] . '(?:[ ]++(.*)|$)/',
                        $line['text'],
                        $matches
                    )
                ) || (
                    $block['data']['type'] === 'ul'
                    && preg_match(
                        '/^' . $block['data']['markerTypeRegex'] . '(?:[ ]++(.*)|$)/',
                        $line['text'],
                        $matches
                    )
                )
            )
        ) {
            if (isset($block['interrupted'])) {
                $block['li']['handler']['argument'] [] = '';

                $block['loose'] = true;

                unset($block['interrupted']);
            }

            unset($block['li']);

            $text = $matches[1] ?? '';

            $block['indent']                 = $line['indent'];
            $block['li']                     = [
                'name'    => 'li',
                'handler' => [
                    'function'    => 'li',
                    'argument'    => [$text],
                    'destination' => 'elements'
                ]
            ];
            $block['element']['elements'] [] = &$block['li'];

            return $block;
        }
        if ($line['indent'] < $requiredIndent && $this->blockList($line)) {
            return null;
        }
        if ($line['text'][0] === '[' && $this->blockReference($line)) {
            return $block;
        }
        if ($line['indent'] >= $requiredIndent) {
            if (isset($block['interrupted'])) {
                $block['li']['handler']['argument'] [] = '';

                $block['loose'] = true;

                unset($block['interrupted']);
            }

            $text = substr($line['body'], $requiredIndent);

            $block['li']['handler']['argument'] [] = $text;

            return $block;
        }

        if (!isset($block['interrupted'])) {
            $text = preg_replace('/^[ ]{0,' . $requiredIndent . '}+/', '', $line['body']);

            $block['li']['handler']['argument'] [] = $text;

            return $block;
        }

        return null;
    }

    protected function blockListComplete(array $block): array
    {
        if (isset($block['loose'])) {
            foreach ($block['element']['elements'] as &$li) {
                if (end($li['handler']['argument']) !== '') {
                    $li['handler']['argument'] [] = '';
                }
            }
        }

        return $block;
    }

    protected function blockQuote(array $line): ?array
    {
        if (preg_match('/^>[ ]?+(.*+)/', $line['text'], $matches)) {
            return [
                'element' => [
                    'name'    => 'blockquote',
                    'handler' => [
                        'function'    => 'linesElements',
                        'argument'    => (array)$matches[1],
                        'destination' => 'elements',
                    ]
                ],
            ];
        }

        return null;
    }

    protected function blockQuoteContinue($line, array $block): ?array
    {
        if (isset($block['interrupted'])) {
            return null;
        }

        if ($line['text'][0] === '>' && preg_match('/^>[ ]?+(.*+)/', $line['text'], $matches)) {
            $block['element']['handler']['argument'] [] = $matches[1];

            return $block;
        }

        $block['element']['handler']['argument'] [] = $line['text'];

        return $block;
    }

    protected function blockRule(array $line): ?array
    {
        $marker = $line['text'][0];
        if (substr_count($line['text'], $marker) >= 3 && rtrim($line['text'], " $marker") === '') {
            return [
                'element' => [
                    'name' => 'hr',
                ],
            ];
        }

        return null;
    }

    protected function blockSetextHeader(array $line, ?array $block = null): ?array
    {
        if (!isset($block) || $block['type'] !== 'Paragraph' || isset($block['interrupted'])) {
            return null;
        }

        if ($line['indent'] < 4 && rtrim(rtrim($line['text'], ' '), $line['text'][0]) === '') {
            $block['element']['name'] = $line['text'][0] === '=' ? 'h1' : 'h2';

            return $block;
        }

        return null;
    }

    protected function blockMarkup(array $line): ?array
    {
        if ($this->markupEscaped || $this->safeMode) {
            return null;
        }
        if (
            preg_match(
                '/^<[\/]?+(\w*)(?:[ ]*+' . $this->regexHtmlAttribute . ')*+[ ]*+(\/)?>/',
                $line['text'],
                $matches
            )
        ) {
            $element = strtolower($matches[1]);

            if (in_array($element, self::$textLevelElements, true)) {
                return null;
            }

            return [
                'name'    => $matches[1],
                'element' => [
                    'rawHtml'   => $line['text'],
                    'autobreak' => true,
                ],
            ];
        }

        return null;
    }

    protected function blockMarkupContinue(array $line, array $block): ?array
    {
        if (isset($block['closed']) || isset($block['interrupted'])) {
            return null;
        }
        $block['element']['rawHtml'] .= "\n" . $line['body'];

        return $block;
    }

    protected function blockReference(array $line): ?array
    {
        if (
            str_contains($line['text'], ']')
            && preg_match('/^\[(.+?)\]:[ ]*+<?(\S+?)>?(?:[ ]+["\'(](.+)["\')])?[ ]*+$/', $line['text'], $matches)
        ) {
            $id   = strtolower($matches[1]);
            $data = [
                'url'   => $matches[2],
                'title' => $matches[3] ?? null,
            ];

            $this->definitionData['Reference'][$id] = $data;

            return [
                'element' => [],
            ];
        }

        return null;
    }

    protected function blockTable(array $line, ?array $block = null): ?array
    {
        if (!isset($block) || $block['type'] !== 'Paragraph' || isset($block['interrupted'])) {
            return null;
        }
        if (
            !str_contains($block['element']['handler']['argument'], '|')
            && !str_contains($line['text'], '|')
            && !str_contains($line['text'], ':')
            || str_contains($block['element']['handler']['argument'], "\n")
        ) {
            return null;
        }

        if (rtrim($line['text'], ' -:|') !== '') {
            return null;
        }

        $alignments = [];

        $divider = $line['text'];
        $divider = trim($divider);
        $divider = trim($divider, '|');

        $dividerCells = explode('|', $divider);
        foreach ($dividerCells as $dividerCell) {
            $dividerCell = trim($dividerCell);
            if ($dividerCell === '') {
                return null;
            }
            $alignment = null;
            if ($dividerCell[0] === ':') {
                $alignment = 'left';
            }
            if (str_ends_with($dividerCell, ':')) {
                $alignment = $alignment === 'left' ? 'center' : 'right';
            }

            $alignments [] = $alignment;
        }

        $headerElements = [];

        $header = $block['element']['handler']['argument'];

        $header = trim($header);
        $header = trim($header, '|');

        $headerCells = explode('|', $header);
        if (count($headerCells) !== count($alignments)) {
            return null;
        }

        foreach ($headerCells as $index => $headerCell) {
            $headerCell    = trim($headerCell);
            $headerElement = [
                'name'    => 'th',
                'handler' => [
                    'function'    => 'lineElements',
                    'argument'    => $headerCell,
                    'destination' => 'elements',
                ]
            ];

            if (isset($alignments[$index])) {
                $alignment = $alignments[$index];

                $headerElement['attributes'] = [
                    'style' => "text-align: $alignment;",
                ];
            }

            $headerElements [] = $headerElement;
        }

        $block = [
            'alignments' => $alignments,
            'identified' => true,
            'element'    => [
                'name'     => 'table',
                'elements' => [],
            ],
        ];

        $block['element']['elements'] [] = [
            'name' => 'thead',
        ];
        $block['element']['elements'] [] = [
            'name'     => 'tbody',
            'elements' => [],
        ];

        $block['element']['elements'][0]['elements'] [] = [
            'name'     => 'tr',
            'elements' => $headerElements,
        ];

        return $block;
    }

    protected function blockTableContinue(array $line, array $block): ?array
    {
        if (isset($block['interrupted'])) {
            return null;
        }

        if (count($block['alignments']) === 1 || $line['text'][0] === '|' || strpos($line['text'], '|')) {
            $elements = [];

            $row = $line['text'];
            $row = trim($row);
            $row = trim($row, '|');

            preg_match_all('/(?:(\\\\[|])|[^|`]|`[^`]++`|`)++/', $row, $matches);

            $cells = array_slice($matches[0], 0, count($block['alignments']));
            foreach ($cells as $index => $cell) {
                $cell    = trim($cell);
                $element = [
                    'name'    => 'td',
                    'handler' => [
                        'function'    => 'lineElements',
                        'argument'    => $cell,
                        'destination' => 'elements',
                    ]
                ];

                if (isset($block['alignments'][$index])) {
                    $element['attributes'] = [
                        'style' => 'text-align: ' . $block['alignments'][$index] . ';',
                    ];
                }

                $elements [] = $element;
            }
            $element = [
                'name'     => 'tr',
                'elements' => $elements,
            ];

            $block['element']['elements'][1]['elements'] [] = $element;

            return $block;
        }

        return null;
    }

    protected function paragraph(array $line): array
    {
        return [
            'type'    => 'Paragraph',
            'element' => [
                'name'    => 'p',
                'handler' => [
                    'function'    => 'lineElements',
                    'argument'    => $line['text'],
                    'destination' => 'elements',
                ],
            ],
        ];
    }

    protected function paragraphContinue(array $line, array $block): ?array
    {
        if (isset($block['interrupted'])) {
            return null;
        }
        $block['element']['handler']['argument'] .= "\n" . $line['text'];

        return $block;
    }

    public function line($text, array $nonNestables = []): string
    {
        return $this->elements($this->lineElements($text, $nonNestables));
    }

    protected function lineElements($text, array $nonNestables = []): array
    {
        // standardize line breaks
        $text         = str_replace(["\r\n", "\r"], "\n", $text);
        $elements     = [];
        $nonNestables = (empty($nonNestables)
            ? []
            : array_combine($nonNestables, $nonNestables)
        );
        // $excerpt is based on the first occurrence of a marker
        while ($excerpt = strpbrk($text, $this->inlineMarkerList)) {
            $marker         = $excerpt[0];
            $markerPosition = strlen($text) - strlen($excerpt);
            $excerptData    = ['text' => $excerpt, 'context' => $text];
            foreach (self::$inlineTypes[$marker] as $inlineType) {
                // check to see if the current inline type is nestable in the current context
                if (isset($nonNestables[$inlineType])) {
                    continue;
                }
                $inline = $this->{"inline$inlineType"}($excerptData);
                if (!isset($inline)) {
                    continue;
                }
                // makes sure that the inline belongs to "our" marker
                if (isset($inline['position']) && $inline['position'] > $markerPosition) {
                    continue;
                }
                // sets a default inline position
                if (!isset($inline['position'])) {
                    $inline['position'] = $markerPosition;
                }
                // cause the new element to 'inherit' our non nestables
                $inline['element']['nonNestables'] = isset($inline['element']['nonNestables'])
                    ? array_merge($inline['element']['nonNestables'], $nonNestables)
                    : $nonNestables;

                // the text that comes before the inline
                $unmarkedText = substr($text, 0, $inline['position']);
                // compile the unmarked text
                $InlineText = $this->inlineText($unmarkedText);
                $elements[] = $InlineText['element'];
                // compile the inline
                $elements[] = $this->extractElement($inline);
                // remove the examined text
                $text = substr($text, $inline['position'] + $inline['extent']);

                continue 2;
            }
            // the marker does not belong to an inline
            $unmarkedText = substr($text, 0, $markerPosition + 1);

            $InlineText = $this->inlineText($unmarkedText);
            $elements[] = $InlineText['element'];

            $text = substr($text, $markerPosition + 1);
        }

        $InlineText = $this->inlineText($text);
        $elements[] = $InlineText['element'];

        foreach ($elements as &$Element) {
            if (!isset($Element['autobreak'])) {
                $Element['autobreak'] = false;
            }
        }

        return $elements;
    }

    protected function inlineText(string $text): array
    {
        $inline = [
            'extent'  => strlen($text),
            'element' => [],
        ];

        $inline['element']['elements'] = self::pregReplaceElements(
            $this->breaksEnabled ? '/[ ]*+\n/' : '/(?:[ ]*+\\\\|[ ]{2,}+)\n/',
            [
                ['name' => 'br'],
                ['text' => "\n"],
            ],
            $text
        );

        return $inline;
    }

    protected function inlineCode(array $excerpt): ?array
    {
        $marker = $excerpt['text'][0];
        if (
            preg_match(
                '/^([' . $marker . ']++)[ ]*+(.+?)[ ]*+(?<![' . $marker . '])\1(?!' . $marker . ')/s',
                $excerpt['text'],
                $matches
            )
        ) {
            $text = $matches[2];
            $text = preg_replace('/[ ]*+\n/', ' ', $text);

            return [
                'extent'  => strlen($matches[0]),
                'element' => [
                    'name' => 'code',
                    'text' => $text,
                ],
            ];
        }

        return null;
    }

    protected function inlineEmailTag(array $excerpt): ?array
    {
        $hostnameLabel   = '[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?';
        $commonMarkEmail = '[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]++@'
            . $hostnameLabel . '(?:\.' . $hostnameLabel . ')*';

        if (
            str_contains($excerpt['text'], '>')
            && preg_match("/^<((mailto:)?$commonMarkEmail)>/i", $excerpt['text'], $matches)
        ) {
            $url = $matches[1];
            if (!isset($matches[2])) {
                $url = "mailto:$url";
            }

            return [
                'extent'  => strlen($matches[0]),
                'element' => [
                    'name'       => 'a',
                    'text'       => $matches[1],
                    'attributes' => [
                        'href' => $url,
                    ],
                ],
            ];
        }

        return null;
    }

    protected function inlineEmphasis(array $excerpt): ?array
    {
        if (!isset($excerpt['text'][1])) {
            return null;
        }
        $marker = $excerpt['text'][0];
        if ($excerpt['text'][1] === $marker && preg_match($this->StrongRegex[$marker], $excerpt['text'], $matches)) {
            $emphasis = 'strong';
        } elseif (preg_match($this->EmRegex[$marker], $excerpt['text'], $matches)) {
            $emphasis = 'em';
        } else {
            return null;
        }

        return [
            'extent'  => strlen($matches[0]),
            'element' => [
                'name'    => $emphasis,
                'handler' => [
                    'function'    => 'lineElements',
                    'argument'    => $matches[1],
                    'destination' => 'elements',
                ]
            ],
        ];
    }

    protected function inlineEscapeSequence(array $excerpt): ?array
    {
        if (isset($excerpt['text'][1]) && in_array($excerpt['text'][1], self::$specialCharacters, true)) {
            return [
                'element' => ['rawHtml' => $excerpt['text'][1]],
                'extent'  => 2,
            ];
        }

        return null;
    }

    protected function inlineImage(array $excerpt): ?array
    {
        if (!isset($excerpt['text'][1]) || $excerpt['text'][1] !== '[') {
            return null;
        }

        $excerpt['text'] = substr($excerpt['text'], 1);

        $link = $this->inlineLink($excerpt);
        if ($link === null) {
            return null;
        }

        $inline = [
            'extent'  => $link['extent'] + 1,
            'element' => [
                'name'       => 'img',
                'attributes' => [
                    'src' => $link['element']['attributes']['href'],
                    'alt' => $link['element']['handler']['argument'],
                ],
                'autobreak'  => true,
            ],
        ];

        $inline['element']['attributes'] += $link['element']['attributes'];

        unset($inline['element']['attributes']['href']);

        return $inline;
    }

    protected function inlineLink(array $excerpt): ?array
    {
        $element   = [
            'name'         => 'a',
            'handler'      => [
                'function'    => 'lineElements',
                'argument'    => null,
                'destination' => 'elements',
            ],
            'nonNestables' => ['Url', 'Link'],
            'attributes'   => [
                'href'  => null,
                'title' => null,
            ],
        ];
        $extent    = 0;
        $remainder = $excerpt['text'];
        if (preg_match('/\[((?:[^][]++|(?R))*+)\]/', $remainder, $matches)) {
            $element['handler']['argument'] = $matches[1];

            $extent += strlen($matches[0]);

            $remainder = substr($remainder, $extent);
        } else {
            return null;
        }

        if (
            preg_match(
                '/^[(]\s*+((?:[^ ()]++|[(][^ )]+[)])++)(?:[ ]+("[^"]*+"|\'[^\']*+\'))?\s*+[)]/',
                $remainder,
                $matches
            )
        ) {
            $element['attributes']['href'] = $matches[1];

            if (isset($matches[2])) {
                $element['attributes']['title'] = substr($matches[2], 1, -1);
            }

            $extent += strlen($matches[0]);
        } else {
            if (preg_match('/^\s*\[(.*?)\]/', $remainder, $matches)) {
                $definition = strlen($matches[1]) ? $matches[1] : $element['handler']['argument'];
                $definition = strtolower($definition);

                $extent += strlen($matches[0]);
            } else {
                $definition = strtolower($element['handler']['argument']);
            }

            if (!isset($this->definitionData['Reference'][$definition])) {
                return null;
            }

            $dd = $this->definitionData['Reference'][$definition];

            $element['attributes']['href']  = $dd['url'];
            $element['attributes']['title'] = $dd['title'];
        }

        return [
            'extent'  => $extent,
            'element' => $element,
        ];
    }

    protected function inlineMarkup(array $excerpt): ?array
    {
        if ($this->markupEscaped || $this->safeMode || !str_contains($excerpt['text'], '>')) {
            return null;
        }

        if ($excerpt['text'][1] === '/' && preg_match('/^<\/\w[\w-]*+[ ]*+>/s', $excerpt['text'], $matches)) {
            return [
                'element' => ['rawHtml' => $matches[0]],
                'extent'  => strlen($matches[0]),
            ];
        }
        if ($excerpt['text'][1] === '!' && preg_match('/^<!---?[^>-](?:-?+[^-])*-->/s', $excerpt['text'], $matches)) {
            return [
                'element' => ['rawHtml' => $matches[0]],
                'extent'  => strlen($matches[0]),
            ];
        }
        if (
            $excerpt['text'][1] !== ' ' && preg_match(
                '/^<\w[\w-]*+(?:[ ]*+' . $this->regexHtmlAttribute . ')*+[ ]*+\/?>/s',
                $excerpt['text'],
                $matches
            )
        ) {
            return [
                'element' => ['rawHtml' => $matches[0]],
                'extent'  => strlen($matches[0]),
            ];
        }

        return null;
    }

    protected function inlineSpecialCharacter(array $excerpt): ?array
    {
        if (
            substr($excerpt['text'], 1, 1) !== ' '
            && str_contains($excerpt['text'], ';')
            && preg_match('/^&(#?+[0-9a-zA-Z]++);/', $excerpt['text'], $matches)
        ) {
            return [
                'element' => ['rawHtml' => '&' . $matches[1] . ';'],
                'extent'  => strlen($matches[0]),
            ];
        }

        return null;
    }

    protected function inlineStrikethrough(array $excerpt): ?array
    {
        if (!isset($excerpt['text'][1])) {
            return null;
        }

        if ($excerpt['text'][1] === '~' && preg_match('/^~~(?=\S)(.+?)(?<=\S)~~/', $excerpt['text'], $matches)) {
            return [
                'extent'  => strlen($matches[0]),
                'element' => [
                    'name'    => 'del',
                    'handler' => [
                        'function'    => 'lineElements',
                        'argument'    => $matches[1],
                        'destination' => 'elements',
                    ]
                ],
            ];
        }

        return null;
    }

    protected function inlineUrl(array $excerpt): ?array
    {
        if ($this->urlsLinked !== true || !isset($excerpt['text'][2]) || $excerpt['text'][2] !== '/') {
            return null;
        }

        if (
            str_contains($excerpt['context'], 'http')
            && preg_match('/\bhttps?+:[\/]{2}[^\s<]+\b\/*+/ui', $excerpt['context'], $matches, PREG_OFFSET_CAPTURE)
        ) {
            $url = $matches[0][0];

            return [
                'extent'   => strlen($matches[0][0]),
                'position' => $matches[0][1],
                'element'  => [
                    'name'       => 'a',
                    'text'       => $url,
                    'attributes' => [
                        'href' => $url,
                    ],
                ],
            ];
        }

        return null;
    }

    protected function inlineUrlTag(array $excerpt): ?array
    {
        if (
            str_contains($excerpt['text'], '>')
            && preg_match(
                '/^<(\w++:\/{2}[^ >]++)>/i',
                $excerpt['text'],
                $matches
            )
        ) {
            $url = $matches[1];

            return [
                'extent'  => strlen($matches[0]),
                'element' => [
                    'name'       => 'a',
                    'text'       => $url,
                    'attributes' => [
                        'href' => $url,
                    ],
                ],
            ];
        }

        return null;
    }

    protected function unmarkedText(string $text): string
    {
        $inline = $this->inlineText($text);

        return $this->element($inline['element']);
    }

    protected function handle(array $element): array
    {
        if (isset($element['handler'])) {
            if (!isset($element['nonNestables'])) {
                $element['nonNestables'] = [];
            }

            if (is_string($element['handler'])) {
                $function = $element['handler'];
                $argument = $element['text'];
                unset($element['text']);
                $destination = 'rawHtml';
            } else {
                $function    = $element['handler']['function'];
                $argument    = $element['handler']['argument'];
                $destination = $element['handler']['destination'];
            }

            $element[$destination] = $this->{$function}($argument, $element['nonNestables']);

            if ($destination === 'handler') {
                $element = $this->handle($element);
            }

            unset($element['handler']);
        }

        return $element;
    }

    protected function handleElementRecursive(array $element): array
    {
        return $this->elementApplyRecursive([$this, 'handle'], $element);
    }

    protected function handleElementsRecursive(array $elements): array
    {
        return $this->elementsApplyRecursive([$this, 'handle'], $elements);
    }

    protected function elementApplyRecursive($closure, array $element)
    {
        $element = $closure($element);

        if (isset($element['elements'])) {
            $element['elements'] = $this->elementsApplyRecursive($closure, $element['elements']);
        } elseif (isset($element['element'])) {
            $element['element'] = $this->elementApplyRecursive($closure, $element['element']);
        }

        return $element;
    }

    protected function elementApplyRecursiveDepthFirst($closure, array $element)
    {
        if (isset($element['elements'])) {
            $element['elements'] = $this->elementsApplyRecursiveDepthFirst($closure, $element['elements']);
        } elseif (isset($element['element'])) {
            $element['element'] = $this->elementsApplyRecursiveDepthFirst($closure, $element['element']);
        }

        return $closure($element);
    }

    protected function elementsApplyRecursive($closure, array $elements): array
    {
        foreach ($elements as &$element) {
            $element = $this->elementApplyRecursive($closure, $element);
        }

        return $elements;
    }

    protected function elementsApplyRecursiveDepthFirst($closure, array $elements): array
    {
        foreach ($elements as &$element) {
            $element = $this->elementApplyRecursiveDepthFirst($closure, $element);
        }

        return $elements;
    }

    protected function element(array $element): string
    {
        if ($this->safeMode) {
            $element = $this->sanitiseElement($element);
        }
        // identity map if element has no handler
        $element = $this->handle($element);
        $hasName = isset($element['name']);
        $markup  = '';
        if ($hasName) {
            $markup .= '<' . $element['name'];
            if (isset($element['attributes'])) {
                foreach ($element['attributes'] as $name => $value) {
                    if ($value === null) {
                        continue;
                    }

                    $markup .= " $name=\"" . self::escape($value) . '"';
                }
            }
        }
        $permitRawHtml = false;
        if (isset($element['text'])) {
            $text = $element['text'];
        } elseif (isset($element['rawHtml'])) {
            $text                   = $element['rawHtml'];
            $allowRawHtmlInSafeMode = isset($element['allowRawHtmlInSafeMode']) && $element['allowRawHtmlInSafeMode'];
            $permitRawHtml          = !$this->safeMode || $allowRawHtmlInSafeMode;
        }
        $hasContent = isset($text) || isset($element['element']) || isset($element['elements']);

        if ($hasContent) {
            $markup .= $hasName ? '>' : '';
            if (isset($element['elements'])) {
                $markup .= $this->elements($element['elements']);
            } elseif (isset($element['element'])) {
                $markup .= $this->element($element['element']);
            } elseif (!$permitRawHtml) {
                $markup .= self::escape($text ?? '', true);
            } else {
                $markup .= ($text ?? '');
            }

            $markup .= $hasName ? '</' . $element['name'] . '>' : '';
        } elseif ($hasName) {
            $markup .= ' />';
        }

        return $markup;
    }

    protected function elements(array $elements): string
    {
        $markup    = '';
        $autoBreak = true;
        foreach ($elements as $element) {
            if (empty($element)) {
                continue;
            }
            $autoBreakNext = $element['autobreak'] ?? isset($element['name']);
            // (autobreak === false) covers both sides of an element
            $autoBreak = !$autoBreak ? $autoBreak : $autoBreakNext;

            $markup    .= ($autoBreak ? "\n" : '') . $this->element($element);
            $autoBreak = $autoBreakNext;
        }

        $markup .= $autoBreak ? "\n" : '';

        return $markup;
    }

    protected function li($lines): array
    {
        $elements = $this->linesElements($lines);
        if (
            !in_array('', $lines, true)
            && isset($elements[0]['name'])
            && $elements[0]['name'] === 'p'
        ) {
            unset($elements[0]['name']);
        }

        return $elements;
    }

    /**
     * Replace occurrences $regexp with $Elements in $text. Return an array of
     * elements representing the replacement.
     */
    protected static function pregReplaceElements(string $regexp, array $elements, string $text): array
    {
        $newElements = [];

        while (preg_match($regexp, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = (int)$matches[0][1];
            $before = substr($text, 0, $offset);
            $after  = substr($text, $offset + strlen($matches[0][0]));

            $newElements[] = ['text' => $before];

            foreach ($elements as $Element) {
                $newElements[] = $Element;
            }

            $text = $after;
        }

        $newElements[] = ['text' => $text];

        return $newElements;
    }

    public function parse(string $text): string
    {
        return $this->text($text);
    }

    protected function sanitiseElement(array $element): array
    {
        static $goodAttribute = '/^[a-zA-Z0-9][a-zA-Z0-9-_]*+$/';

        static $safeUrlNameToAtt = [
            'a'   => 'href',
            'img' => 'src',
        ];

        if (!isset($element['name'])) {
            unset($element['attributes']);
            return $element;
        }

        if (isset($safeUrlNameToAtt[$element['name']])) {
            $element = $this->filterUnsafeUrlInAttribute($element, $safeUrlNameToAtt[$element['name']]);
        }

        if (!empty($element['attributes'])) {
            foreach ($element['attributes'] as $att => $val) {
                // filter out badly parsed attribute
                if (!preg_match($goodAttribute, $att)) {
                    unset($element['attributes'][$att]);
                } elseif (self::striAtStart($att, 'on')) {  // dump onevent attribute
                    unset($element['attributes'][$att]);
                }
            }
        }

        return $element;
    }

    protected function filterUnsafeUrlInAttribute(array $element, $attribute): array
    {
        foreach (self::$safeLinksWhitelist as $scheme) {
            if (self::striAtStart($element['attributes'][$attribute], $scheme)) {
                return $element;
            }
        }

        $element['attributes'][$attribute] = str_replace(':', '%3A', $element['attributes'][$attribute]);

        return $element;
    }

    protected static function escape(string $text, bool $allowQuotes = false): string
    {
        return htmlspecialchars($text, $allowQuotes ? ENT_NOQUOTES : ENT_QUOTES, 'UTF-8');
    }

    protected static function striAtStart(string $string, string $needle): bool
    {
        $len = strlen($needle);

        if ($len > strlen($string)) {
            return false;
        }

        return stripos($string, strtolower($needle)) === 0;
    }

    public static function instance(string $name = 'default')
    {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        $instance = new static();

        self::$instances[$name] = $instance;

        return $instance;
    }
}

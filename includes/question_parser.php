<?php
/**
 * Turns a plain text question paper into structured questions.
 *
 * The expected layout is deliberately forgiving - these are all accepted:
 *
 *   Q1. What is the full form of HTML?      1. ...    1) ...    Q.1 ...
 *   A) Hyper Text Markup Language           A. ...    (A) ...   a) ...
 *   Answer: A                               Ans: A    Correct: Hyper Text...
 *   Marks: 2                                [2 marks] in the question line
 *   Explanation: ...
 *   Difficulty: easy                        Category: HTML
 *
 * A question with no options becomes a subjective (written answer) question;
 * tag it explicitly with [Subjective] if you like. A question whose answer is
 * True or False becomes a True/False question.
 *
 * Nothing is written to the database from here - the importer shows the parsed
 * result for the faculty to check and edit first.
 *
 * @return array<int,array<string,mixed>>
 */
function parse_questions(string $text): array
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

    $questions = [];
    $q         = null;
    $context   = null;      // where a continuation line belongs: question|option|explanation|model
    $lastNum   = 0;         // number of the question opened most recently

    $flush = static function () use (&$q, &$questions) {
        if ($q !== null && trim($q['text']) !== '') {
            $questions[] = $q;
        }
        $q = null;
    };

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);

        if ($line === '') {
            $context = null;
            continue;
        }

        // ---------------- New question ----------------
        if (preg_match('/^(Q\s*\.?\s*)?(\d{1,3})\s*[\.\)\:\-]\s*(.*)$/i', $line, $m)
            && trim($m[3]) !== '') {
            $hasQPrefix = trim((string)$m[1]) !== '';
            $number     = (int)$m[2];

            // A bare "1) Newton" sitting inside a question is an option, not a
            // new question. Only an explicit "Q" prefix, or a number that
            // continues the sequence, opens the next question.
            $startsQuestion = $hasQPrefix || $q === null || $number === $lastNum + 1;

            if ($startsQuestion) {
                $flush();
                $q = new_question();
                $q['number'] = $number;
                $q['text']   = trim($m[3]);
                $lastNum     = $number;
                $context     = 'question';
                extract_inline_tags($q);
                continue;
            }
        }

        // Questions can also start with a bare tag, e.g. "[Subjective] Explain ..."
        if ($q === null && preg_match('/^\[(sub|subjective|descriptive|long|short)\]/i', $line)) {
            $q = new_question();
            $q['text'] = $line;
            $context   = 'question';
            extract_inline_tags($q);
            continue;
        }

        if ($q === null) {
            continue;               // preamble such as a paper heading
        }

        // ---------------- Option ----------------
        if (preg_match('/^\(?([A-Fa-f])\)?\s*[\.\)\:\-]\s+(.+)$/', $line, $m)) {
            $index = ord(strtoupper($m[1])) - 65;
            if ($index === count($q['options'])) {         // options must be in order
                $q['options'][] = trim($m[2]);
                $context = 'option';
                continue;
            }
        }

        // ---------------- Answer ----------------
        if (preg_match('/^(?:Ans|Answer|Correct(?:\s+Answer)?|Sol|Solution)\s*[:\.\-]\s*(.+)$/i',
                       $line, $m)) {
            $q['answer_raw'] = trim($m[1]);
            $context = null;
            continue;
        }

        // ---------------- Marks ----------------
        if (preg_match('/^Marks?\s*[:\.\-]\s*([0-9]+(?:\.[0-9]+)?)/i', $line, $m)) {
            $q['marks'] = (float)$m[1];
            $context = null;
            continue;
        }

        // ---------------- Explanation ----------------
        if (preg_match('/^(?:Explanation|Explain|Exp|Reason)\s*[:\.\-]\s*(.*)$/i', $line, $m)) {
            $q['explanation'] = trim($m[1]);
            $context = 'explanation';
            continue;
        }

        // ---------------- Model answer (subjective) ----------------
        if (preg_match('/^(?:Model\s*Answer|Expected\s*Answer|Key\s*Points)\s*[:\.\-]\s*(.*)$/i',
                       $line, $m)) {
            $q['model_answer'] = trim($m[1]);
            $context = 'model';
            continue;
        }

        // ---------------- Difficulty / category ----------------
        if (preg_match('/^Difficulty\s*[:\.\-]\s*(easy|medium|hard)/i', $line, $m)) {
            $q['difficulty'] = strtolower($m[1]);
            $context = null;
            continue;
        }
        if (preg_match('/^(?:Category|Topic|Chapter|Unit)\s*[:\.\-]\s*(.+)$/i', $line, $m)) {
            $q['category'] = trim($m[1]);
            $context = null;
            continue;
        }

        // ---------------- Continuation of the previous block ----------------
        switch ($context) {
            case 'question':
                $q['text'] .= ' ' . $line;
                extract_inline_tags($q);
                break;
            case 'option':
                $q['options'][count($q['options']) - 1] .= ' ' . $line;
                break;
            case 'explanation':
                $q['explanation'] .= ' ' . $line;
                break;
            case 'model':
                $q['model_answer'] .= ' ' . $line;
                break;
            default:
                $q['text'] .= ' ' . $line;
        }
    }
    $flush();

    return array_map('finalise_question', $questions);
}

/** A blank question record. */
function new_question(): array
{
    return [
        'number'       => 0,
        'text'         => '',
        'options'      => [],
        'answer_raw'   => '',
        'correct'      => null,
        'type'         => '',
        'forced_type'  => '',
        'marks'        => null,
        'explanation'  => '',
        'model_answer' => '',
        'difficulty'   => 'easy',
        'category'     => '',
        'warnings'     => [],
    ];
}

/**
 * Pull "[Subjective]", "[MCQ]" and "[5 marks]" style tags out of the question
 * text and store them as fields.
 */
function extract_inline_tags(array &$q): void
{
    // Type tag
    if (preg_match('/\[\s*(sub|subjective|descriptive|long|short)\s*(?:answer)?\s*\]/i',
                   $q['text'], $m)) {
        $q['forced_type'] = 'subjective';
        $q['text'] = trim(str_replace($m[0], '', $q['text']));
    } elseif (preg_match('/\[\s*(mcq|objective|multiple\s*choice)\s*\]/i', $q['text'], $m)) {
        $q['forced_type'] = 'mcq';
        $q['text'] = trim(str_replace($m[0], '', $q['text']));
    } elseif (preg_match('/\[\s*(true\s*\/?\s*false|tf)\s*\]/i', $q['text'], $m)) {
        $q['forced_type'] = 'truefalse';
        $q['text'] = trim(str_replace($m[0], '', $q['text']));
    }

    // Marks written inline: [5 marks] or (5 marks)
    if (preg_match('/[\[\(]\s*([0-9]+(?:\.[0-9]+)?)\s*marks?\s*[\]\)]/i', $q['text'], $m)) {
        $q['marks'] = (float)$m[1];
        $q['text'] = trim(str_replace($m[0], '', $q['text']));
    }

    // "(True/False)" written at the end of the sentence
    if (preg_match('/\(\s*true\s*\/\s*false\s*\)/i', $q['text'], $m)) {
        $q['forced_type'] = $q['forced_type'] ?: 'truefalse';
        $q['text'] = trim(str_replace($m[0], '', $q['text']));
    }

    $q['text'] = trim(preg_replace('/\s{2,}/', ' ', $q['text']));
}

/** Decide the final type, resolve the correct option and collect warnings. */
function finalise_question(array $q): array
{
    $q['text'] = trim($q['text']);
    $q['options'] = array_values(array_filter(array_map('trim', $q['options']),
                                              static fn($o) => $o !== ''));
    $answer = trim($q['answer_raw']);

    // ---------- Type ----------
    $isTrueFalseAnswer = preg_match('/^(true|false|t|f)$/i', $answer) === 1;

    if ($q['forced_type'] === 'subjective') {
        $q['type'] = 'subjective';
    } elseif ($q['forced_type'] === 'truefalse'
              || (count($q['options']) === 0 && $isTrueFalseAnswer)
              || (count($q['options']) === 2
                  && preg_match('/^true$/i', $q['options'][0])
                  && preg_match('/^false$/i', $q['options'][1]))) {
        $q['type']    = 'truefalse';
        $q['options'] = ['True', 'False'];
    } elseif (count($q['options']) >= 2) {
        $q['type'] = 'mcq';
    } else {
        $q['type'] = 'subjective';
    }

    // ---------- Correct answer ----------
    if ($q['type'] === 'subjective') {
        $q['correct'] = null;
        if ($answer !== '' && $q['model_answer'] === '') {
            $q['model_answer'] = $answer;      // "Answer: ..." on a written question
        }
        if ($q['marks'] === null) {
            $q['marks'] = 5.0;                 // sensible default for a written answer
        }
    } else {
        if ($q['marks'] === null) {
            $q['marks'] = 1.0;
        }

        if ($answer === '') {
            $q['warnings'][] = 'No answer key found - choose the correct option below.';
        } elseif ($q['type'] === 'truefalse') {
            $q['correct'] = preg_match('/^(t|true)$/i', $answer) ? 0 : 1;
        } elseif (preg_match('/^\(?([A-Fa-f])\)?$/', $answer, $m)) {
            $index = ord(strtoupper($m[1])) - 65;
            if (isset($q['options'][$index])) {
                $q['correct'] = $index;
            } else {
                $q['warnings'][] = 'Answer key "' . $answer . '" does not match any option.';
            }
        } else {
            // Answer given as text, possibly prefixed with its letter.
            $needle = preg_replace('/^\(?[A-Fa-f]\)?\s*[\.\)\:\-]?\s*/', '', $answer);
            foreach ($q['options'] as $i => $opt) {
                if (strcasecmp(trim($opt), trim((string)$needle)) === 0) {
                    $q['correct'] = $i;
                    break;
                }
            }
            if ($q['correct'] === null) {
                $q['warnings'][] = 'Answer "' . $answer . '" did not match any option.';
            }
        }

        if (count($q['options']) < 2) {
            $q['warnings'][] = 'Fewer than two options were found.';
        }
    }

    if (mb_strlen($q['text']) < 5) {
        $q['warnings'][] = 'The question text looks too short.';
    }

    return $q;
}

/**
 * The sample layout shown on the import screen and used by the tests.
 */
function sample_question_format(): string
{
    return <<<'TXT'
Q1. What is the full form of HTML?
A) Hyper Text Markup Language
B) High Text Machine Language
C) Hyperlink Text Management Language
D) High-level Text Markup Language
Answer: A
Marks: 1
Category: HTML
Difficulty: easy
Explanation: HTML stands for Hyper Text Markup Language.

Q2. The CPU is an output device. (True/False)
Answer: False
Marks: 1
Explanation: The CPU is the processing unit, not an output device.

Q3. [Subjective] Explain the difference between RAM and ROM with examples.
Marks: 5
Model Answer: RAM is volatile read/write memory used for running programs; ROM
is non volatile and stores firmware.
TXT;
}

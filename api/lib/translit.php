<?php
declare(strict_types=1);

/**
 * Key data into English.
 *
 * What PropertyBank translates is names — city, district, street — and
 * names are not translated but transliterated, with the kind of street
 * ("вулиця", "провулок") put into English. That is done here, by rule, with
 * no translation service:
 *
 *   Ukrainian  the official national system (Cabinet of Ministers of Ukraine,
 *              resolution 55 of 27 January 2010), the one on Kyiv's street
 *              signs: Михайлівський → Mykhailivskyi, Київ → Kyiv,
 *              Ярославів Вал → Yaroslaviv Val
 *   Russian    the usual BGN-style system, for text with ы, э, ё or ъ —
 *              many OLX listings are written in Russian
 *
 *   "Михайлівський пров." + "9"  →  "9 Mykhailivskyi Lane"
 */

const UK_MAP = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh',
    'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
    'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
    'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'iu', 'я' => 'ia',
    // Russian letters that turn up in Ukrainian text by mistake
    'ы' => 'y', 'э' => 'e', 'ё' => 'io', 'ъ' => '',
];
/** At the start of a word є, ї, й, ю, я are written differently. */
const UK_INITIAL = ['є' => 'ye', 'ї' => 'yi', 'й' => 'y', 'ю' => 'yu', 'я' => 'ya'];

const RU_MAP = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z',
    'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
    'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
    'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g',
];

/** Kinds of street, as written in Ukrainian and Russian (full and abbreviated), and in English. */
const STREET_TYPES = [
    'Street'     => ['вулиця', 'вулиці', 'вулицю', 'вул', 'улица', 'улицы', 'ул'],
    'Lane'       => ['провулок', 'провулку', 'пров', 'переулок', 'переулка', 'пер'],
    'Avenue'     => ['проспект', 'проспекту', 'просп', 'пр-т', 'п-кт', 'п-т', 'пр-кт', 'п-р', 'пр'],
    'Boulevard'  => ['бульвар', 'бульвару', 'бульв', 'б-р'],
    'Square'     => ['площа', 'площі', 'площадь', 'пл'],
    'Descent'    => ['узвіз', 'спуск'],
    'Embankment' => ['набережна', 'набережная', 'наб'],
    'Highway'    => ['шосе', 'шоссе'],
    'Passage'    => ['проїзд', 'проезд'],
    'Alley'      => ['алея', 'аллея'],
    'Dead End'   => ['тупик'],
];

/** Fixed English names, where the transliteration is not what English uses. */
const EN_NAMES = [
    'київ' => 'Kyiv', 'киев' => 'Kyiv', 'львів' => 'Lviv', 'одеса' => 'Odesa', 'одесса' => 'Odesa',
    'харків' => 'Kharkiv', 'дніпро' => 'Dnipro',
];

/** Words that already say what kind of street it is: Ярославів Вал, Майдан Незалежності. */
const STREET_SELF_NAMED = ['вал', 'майдан'];

function is_russian_text(string $s): bool
{
    if (preg_match('/[ыэёъЫЭЁЪ]/u', $s)) {
        return true;
    }
    // No Ukrainian letter, and a Russian adjective ending: "Дегтяревская".
    return !preg_match("/[іїєґІЇЄҐ'’ʼ]/u", $s)
        && (bool) preg_match('/(ая|яя|ое|ской|ская|ского|ский)(?!\p{L})/u', mb_strtolower($s));
}

/** Cyrillic to Latin. Latin, digits and punctuation pass through. */
function translit(string $s): string
{
    $ru = is_russian_text($s);
    $map = $ru ? RU_MAP : UK_MAP;
    // The apostrophe in Ukrainian (м'ята, Лук'янівська) is dropped.
    $s = preg_replace("/(?<=\p{Cyrillic})['’ʼ`](?=\p{Cyrillic})/u", '', $s) ?? $s;
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = '';
    $prevLetter = false;
    $n = count($chars);
    for ($i = 0; $i < $n; $i++) {
        $c = $chars[$i];
        $lc = mb_strtolower($c);
        $upper = $lc !== $c;
        if (!isset($map[$lc])) {
            $out .= $c;
            $prevLetter = (bool) preg_match('/\p{L}/u', $c);
            continue;
        }
        // зг is written zgh, so that it is not read as "zh".
        if (!$ru && $lc === 'г' && $i > 0 && mb_strtolower($chars[$i - 1]) === 'з') {
            $t = 'gh';
        } elseif (!$ru && !$prevLetter && isset(UK_INITIAL[$lc])) {
            $t = UK_INITIAL[$lc];
        } else {
            $t = $map[$lc];
        }
        if ($upper && $t !== '') {
            // A capital stays a capital; in a word all in capitals, the whole group does.
            $nextUpper = $i + 1 < $n && mb_strtolower($chars[$i + 1]) !== $chars[$i + 1];
            $t = $nextUpper ? strtoupper($t) : ucfirst($t);
        }
        $out .= $t;
        $prevLetter = true;
    }
    return $out;
}

/** A place name in English: the fixed name if there is one, else the transliteration. */
function name_en(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $k = mb_strtolower($s);
    if (isset(EN_NAMES[$k])) {
        return EN_NAMES[$k];
    }
    return translit($s);
}

/** A district: "Шевченківський" / "Шевченковский р-н" → "Shevchenkivskyi". */
function district_en(string $s): string
{
    $s = trim(preg_replace('/\s*(район|р-н|р\.)\s*$/u', '', $s) ?? $s);
    // The Russian form of a Kyiv district name, written the Ukrainian way.
    $s = preg_replace('/ковский$/u', 'ківський', $s) ?? $s;
    $s = preg_replace('/ский$/u', 'ський', $s) ?? $s;
    return name_en($s);
}

/**
 * Splits a street into its kind and its name.
 * "Михайлівський пров." → ['Lane', 'Михайлівський'];  "вулиця Глибочицька" → ['Street', 'Глибочицька'].
 *
 * @return array{0:?string,1:string}
 */
function street_split(string $street): array
{
    $street = trim(preg_replace('/\s+/u', ' ', $street) ?? $street, " ,.");
    $words = explode(' ', $street);
    $norm = static fn (string $w): string => rtrim(mb_strtolower($w), '.');
    foreach ([0, count($words) - 1] as $pos) {
        if (count($words) < 2) {
            break;
        }
        $w = $norm($words[$pos]);
        foreach (STREET_TYPES as $en => $forms) {
            if (in_array($w, $forms, true)) {
                array_splice($words, $pos, 1);
                return [$en, trim(implode(' ', $words), " ,.")];
            }
        }
    }
    return [null, $street];
}

/** "Михайлівський пров." → "Mykhailivskyi Lane"; a street of no stated kind is a Street. */
function street_en(string $street): string
{
    if (trim($street) === '') {
        return '';
    }
    [$type, $name] = street_split($street);
    if ($type === null) {
        foreach (explode(' ', mb_strtolower($name)) as $w) {
            if (in_array($w, STREET_SELF_NAMED, true)) {
                return translit($name);
            }
        }
    }
    return trim(translit($name) . ' ' . ($type ?? 'Street'));
}

/** "Михайлівський пров." + "9" → "9 Mykhailivskyi Lane". */
function address_en(string $street, string $house): string
{
    $s = street_en($street);
    if ($s === '') {
        return '';
    }
    $house = trim($house);
    return $house !== '' ? translit($house) . ' ' . $s : $s;
}

function address_orig(string $street, string $house): string
{
    $street = trim($street);
    return $street === '' ? '' : trim($street . ($house !== '' ? ', ' . $house : ''));
}

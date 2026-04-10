<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Superadmin'])) die('Kein Zugriff.');

$db = getDB();

// Vollständige Englisch → Deutsch Ländername-Übersetzung
// Längere Namen zuerst (verhindert Teilersetzungen)
$replacements = [
    // Mehrwörtige zuerst
    'United States of America'              => 'USA',
    'United Kingdom'                        => 'Großbritannien',
    'New Zealand'                           => 'Neuseeland',
    'South Africa'                          => 'Südafrika',
    'South Korea'                           => 'Südkorea',
    'North Korea'                           => 'Nordkorea',
    'Saudi Arabia'                          => 'Saudi-Arabien',
    'United Arab Emirates'                  => 'Vereinigte Arabische Emirate',
    'Czech Republic'                        => 'Tschechien',
    'Dominican Republic'                    => 'Dominikanische Republik',
    'Democratic Republic of the Congo'      => 'Demokratische Republik Kongo',
    'Republic of the Congo'                 => 'Republik Kongo',
    'Ivory Coast'                           => 'Elfenbeinküste',
    'North Macedonia'                       => 'Nordmazedonien',
    'Bosnia and Herzegovina'                => 'Bosnien und Herzegowina',
    'Trinidad and Tobago'                   => 'Trinidad und Tobago',
    'Antigua and Barbuda'                   => 'Antigua und Barbuda',
    'Saint Kitts and Nevis'                 => 'St. Kitts und Nevis',
    'Saint Vincent and the Grenadines'      => 'St. Vincent und die Grenadinen',
    'São Tomé and Príncipe'                 => 'São Tomé und Príncipe',
    'Sao Tome and Principe'                 => 'São Tomé und Príncipe',
    'Papua New Guinea'                      => 'Papua-Neuguinea',
    'East Timor'                            => 'Osttimor',
    'Timor-Leste'                           => 'Osttimor',
    'Solomon Islands'                       => 'Salomonen',
    'Marshall Islands'                      => 'Marshallinseln',
    'Faroe Islands'                         => 'Färöer',
    'Cayman Islands'                        => 'Kaimaninseln',
    'Virgin Islands'                        => 'Jungferninseln',
    'Canary Islands'                        => 'Kanarische Inseln',
    'Cape Verde'                            => 'Kap Verde',
    'Costa Rica'                            => 'Costa Rica',
    'Puerto Rico'                           => 'Puerto Rico',
    'Sri Lanka'                             => 'Sri Lanka',
    'El Salvador'                           => 'El Salvador',
    'New Caledonia'                         => 'Neukaledonien',
    'French Polynesia'                      => 'Französisch-Polynesien',
    'French Guiana'                         => 'Französisch-Guayana',
    'Netherlands Antilles'                  => 'Niederländische Antillen',
    'West Germany'                          => 'Westdeutschland',
    'East Germany'                          => 'Ostdeutschland',
    'Soviet Union'                          => 'Sowjetunion',
    'United States'                         => 'USA',
    'South Sudan'                           => 'Südsudan',
    'Western Sahara'                        => 'Westsahara',
    'Central African Republic'              => 'Zentralafrikanische Republik',
    'Equatorial Guinea'                     => 'Äquatorialguinea',
    'Sierra Leone'                          => 'Sierra Leone',
    'Burkina Faso'                          => 'Burkina Faso',
    'Guinea-Bissau'                         => 'Guinea-Bissau',
    'Hong Kong'                             => 'Hongkong',
    'North Vietnam'                         => 'Nordvietnam',
    'South Vietnam'                         => 'Südvietnam',

    // Einwörtige
    'Afghanistan'       => 'Afghanistan',
    'Albania'           => 'Albanien',
    'Algeria'           => 'Algerien',
    'Andorra'           => 'Andorra',
    'Angola'            => 'Angola',
    'Argentina'         => 'Argentinien',
    'Armenia'           => 'Armenien',
    'Australia'         => 'Australien',
    'Austria'           => 'Österreich',
    'Azerbaijan'        => 'Aserbaidschan',
    'Bahamas'           => 'Bahamas',
    'Bahrain'           => 'Bahrain',
    'Bangladesh'        => 'Bangladesch',
    'Barbados'          => 'Barbados',
    'Belarus'           => 'Weißrussland',
    'Belgium'           => 'Belgien',
    'Belize'            => 'Belize',
    'Benin'             => 'Benin',
    'Bhutan'            => 'Bhutan',
    'Bolivia'           => 'Bolivien',
    'Botswana'          => 'Botswana',
    'Brazil'            => 'Brasilien',
    'Brunei'            => 'Brunei',
    'Bulgaria'          => 'Bulgarien',
    'Burundi'           => 'Burundi',
    'Cambodia'          => 'Kambodscha',
    'Cameroon'          => 'Kamerun',
    'Canada'            => 'Kanada',
    'Chad'              => 'Tschad',
    'Chile'             => 'Chile',
    'China'             => 'China',
    'Colombia'          => 'Kolumbien',
    'Comoros'           => 'Komoren',
    'Croatia'           => 'Kroatien',
    'Cuba'              => 'Kuba',
    'Cyprus'            => 'Zypern',
    'Denmark'           => 'Dänemark',
    'Djibouti'          => 'Dschibuti',
    'Ecuador'           => 'Ecuador',
    'Egypt'             => 'Ägypten',
    'Eritrea'           => 'Eritrea',
    'Estonia'           => 'Estland',
    'Ethiopia'          => 'Äthiopien',
    'Fiji'              => 'Fidschi',
    'Finland'           => 'Finnland',
    'France'            => 'Frankreich',
    'Gabon'             => 'Gabun',
    'Gambia'            => 'Gambia',
    'Georgia'           => 'Georgien',
    'Germany'           => 'Deutschland',
    'Ghana'             => 'Ghana',
    'Greece'            => 'Griechenland',
    'Grenada'           => 'Grenada',
    'Guatemala'         => 'Guatemala',
    'Guinea'            => 'Guinea',
    'Guyana'            => 'Guyana',
    'Haiti'             => 'Haiti',
    'Honduras'          => 'Honduras',
    'Hungary'           => 'Ungarn',
    'Iceland'           => 'Island',
    'India'             => 'Indien',
    'Indonesia'         => 'Indonesien',
    'Iran'              => 'Iran',
    'Iraq'              => 'Irak',
    'Ireland'           => 'Irland',
    'Israel'            => 'Israel',
    'Italy'             => 'Italien',
    'Jamaica'           => 'Jamaika',
    'Japan'             => 'Japan',
    'Jordan'            => 'Jordanien',
    'Kazakhstan'        => 'Kasachstan',
    'Kenya'             => 'Kenia',
    'Kosovo'            => 'Kosovo',
    'Kuwait'            => 'Kuwait',
    'Kyrgyzstan'        => 'Kirgisistan',
    'Laos'              => 'Laos',
    'Latvia'            => 'Lettland',
    'Lebanon'           => 'Libanon',
    'Lesotho'           => 'Lesotho',
    'Liberia'           => 'Liberia',
    'Libya'             => 'Libyen',
    'Liechtenstein'     => 'Liechtenstein',
    'Lithuania'         => 'Litauen',
    'Luxembourg'        => 'Luxemburg',
    'Madagascar'        => 'Madagaskar',
    'Malawi'            => 'Malawi',
    'Malaysia'          => 'Malaysia',
    'Maldives'          => 'Malediven',
    'Mali'              => 'Mali',
    'Malta'             => 'Malta',
    'Mauritania'        => 'Mauretanien',
    'Mauritius'         => 'Mauritius',
    'Mexico'            => 'Mexiko',
    'Moldova'           => 'Moldawien',
    'Monaco'            => 'Monaco',
    'Mongolia'          => 'Mongolei',
    'Montenegro'        => 'Montenegro',
    'Morocco'           => 'Marokko',
    'Mozambique'        => 'Mosambik',
    'Myanmar'           => 'Myanmar',
    'Namibia'           => 'Namibia',
    'Nepal'             => 'Nepal',
    'Netherlands'       => 'Niederlande',
    'Nicaragua'         => 'Nicaragua',
    'Niger'             => 'Niger',
    'Nigeria'           => 'Nigeria',
    'Norway'            => 'Norwegen',
    'Oman'              => 'Oman',
    'Pakistan'          => 'Pakistan',
    'Palestine'         => 'Palästina',
    'Panama'            => 'Panama',
    'Paraguay'          => 'Paraguay',
    'Peru'              => 'Peru',
    'Philippines'       => 'Philippinen',
    'Poland'            => 'Polen',
    'Portugal'          => 'Portugal',
    'Qatar'             => 'Katar',
    'Romania'           => 'Rumänien',
    'Russia'            => 'Russland',
    'Rwanda'            => 'Ruanda',
    'Senegal'           => 'Senegal',
    'Serbia'            => 'Serbien',
    'Singapore'         => 'Singapur',
    'Slovakia'          => 'Slowakei',
    'Slovenia'          => 'Slowenien',
    'Somalia'           => 'Somalia',
    'Spain'             => 'Spanien',
    'Sudan'             => 'Sudan',
    'Suriname'          => 'Surinam',
    'Swaziland'         => 'Swasiland',
    'Eswatini'          => 'Eswatini',
    'Sweden'            => 'Schweden',
    'Switzerland'       => 'Schweiz',
    'Syria'             => 'Syrien',
    'Taiwan'            => 'Taiwan',
    'Tajikistan'        => 'Tadschikistan',
    'Tanzania'          => 'Tansania',
    'Thailand'          => 'Thailand',
    'Togo'              => 'Togo',
    'Tunisia'           => 'Tunesien',
    'Turkey'            => 'Türkei',
    'Turkmenistan'      => 'Turkmenistan',
    'Uganda'            => 'Uganda',
    'Ukraine'           => 'Ukraine',
    'Uruguay'           => 'Uruguay',
    'Uzbekistan'        => 'Usbekistan',
    'Venezuela'         => 'Venezuela',
    'Vietnam'           => 'Vietnam',
    'Yemen'             => 'Jemen',
    'Yugoslavia'        => 'Jugoslawien',
    'Zambia'            => 'Sambia',
    'Zimbabwe'          => 'Simbabwe',
    'Czechoslovakia'    => 'Tschechoslowakei',
];

$doRun = isset($_POST['run']);

// Fetch all distinct country values from DB
$allCountries = $db->query("SELECT DISTINCT country FROM movies WHERE country != '' AND country IS NOT NULL ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);

// Find which replacements actually appear in the DB
$relevant = [];
foreach ($replacements as $from => $to) {
    if ($from === $to) continue; // skip identical
    foreach ($allCountries as $val) {
        if (mb_strpos($val, $from) !== false) {
            if (!isset($relevant[$from])) {
                $s = $db->prepare("SELECT COUNT(*) FROM movies WHERE country LIKE ?");
                $s->execute(['%'.$from.'%']);
                $relevant[$from] = ['to' => $to, 'count' => (int)$s->fetchColumn(), 'changed' => null];
            }
            break;
        }
    }
}

if ($doRun) {
    foreach ($relevant as $from => &$info) {
        $s = $db->prepare("UPDATE movies SET country = REPLACE(country, ?, ?) WHERE country LIKE ?");
        $s->execute([$from, $info['to'], '%'.$from.'%']);
        $info['changed'] = $s->rowCount();
    }
    unset($info);
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Länder-Korrektur</title>
<style>
body { font-family: monospace; background: #1a1a2e; color: #ccc; padding: 2rem; }
h2   { color: #e8b84b; }
table { border-collapse: collapse; margin: 1rem 0; width: 100%; max-width: 700px; }
td, th { border: 1px solid #333; padding: .35rem .75rem; }
th   { background: #2a2a4e; color: #e8b84b; text-align: left; }
tr:hover td { background: rgba(255,255,255,.03); }
.ok  { color: #4caf50; }
.cnt { color: rgba(255,255,255,.5); text-align: right; }
.btn { background: #e8b84b; color: #000; border: none; padding: .5rem 1.5rem;
       cursor: pointer; font-size: 1rem; border-radius: 4px; margin-top: 1rem; }
.btn:hover { background: #f0c860; }
.done { color: #4caf50; font-size: 1.2rem; margin-top: 1rem; }
</style></head><body>
<h2>Länder-Korrektur: Englisch → Deutsch</h2>

<?php if (empty($relevant)): ?>
<p class="ok">✔ Alle Ländernamen sind bereits auf Deutsch – nichts zu tun.</p>
<?php elseif (!$doRun): ?>
<p><?= count($relevant) ?> englische Ländernamen gefunden:</p>
<table>
<tr><th>Englisch</th><th>Deutsch</th><th>Filme</th></tr>
<?php foreach ($relevant as $from => $info): ?>
<tr>
    <td><?= htmlspecialchars($from) ?></td>
    <td><?= htmlspecialchars($info['to']) ?></td>
    <td class="cnt"><?= $info['count'] ?></td>
</tr>
<?php endforeach; ?>
</table>
<form method="post">
    <button class="btn" name="run" value="1">✔ Jetzt alle umbenennen</button>
</form>
<?php else: ?>
<p><?= count($relevant) ?> Ersetzungen durchgeführt:</p>
<table>
<tr><th>Englisch</th><th>Deutsch</th><th>Geändert</th></tr>
<?php foreach ($relevant as $from => $info): ?>
<tr>
    <td><?= htmlspecialchars($from) ?></td>
    <td><?= htmlspecialchars($info['to']) ?></td>
    <td class="cnt ok"><?= $info['changed'] ?> Zeilen</td>
</tr>
<?php endforeach; ?>
</table>
<p class="done">✔ Fertig! Diese Seite kann jetzt geschlossen werden.</p>
<?php endif; ?>
</body></html>

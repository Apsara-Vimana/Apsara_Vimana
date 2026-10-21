<?php
declare(strict_types=1);

/* ============================================================================
   APSARA VIMANA ONE — api/engine.php  (v6.2 — YOCO + Robot gratis)
   Motor local del agente Apsara Vimana.
   Estilo YOCO: analizar_consulta() lee el prompt UNA vez → $notas;
   despachar_notas() ejecuta solo la rama útil (no recorre todas las capas).

   Orquestación GRATUITA (sin APIs de pago):
     1) LOCAL (glosario, PERSONAS, música, biblioteca, caché, matemáticas)
     2) SEARXNG opcional (env SEARXNG_URL o lista corta; si falla se salta)
     3) DuckDuckGo Instant Answer (sin key)
     4) Wikipedia (SIEMPRE último recurso; omitida en actualidad/ciencia/etc.)
     5) Botón Yahoo siempre disponible

   Matemáticas: calculadora local segura (sin eval arbitrario).
   Alojamiento: GitHub + Render / PHP 8.x + cURL. Sin base de datos.
   Compatible con: index.html + js/apsara.js (FormData).
   ============================================================================ */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/* Evitar que notices/warnings/fatales rompan el JSON (cliente mostraría "No pude completar...") */
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
set_exception_handler(static function (Throwable $e): void {
    echo json_encode([
        'ok'      => true,
        'agente'  => 'Apsara Vimana',
        'reply'   => 'Hubo un problema interno al procesar tu consulta. Inténtalo de nuevo en unos segundos.',
        'image'   => 'imagenes/estados/Reconectando.jpg',
        'links'   => [],
        'options' => [],
        'state'   => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});


/* ----------------------------------------------------------------------------
   1. CONFIGURACIÓN
   ---------------------------------------------------------------------------- */

const AGENTE   = 'Apsara Vimana';
const SITE_URL = 'https://vimana.rf.gd/';
const IA_URL   = 'https://vimana.rf.gd/ia/';

const WIKI_API_URL = 'https://es.wikipedia.org/w/api.php';
const EXT_TIMEOUT  = 4;            // segundos por petición externa

/* Instancias SearXNG de respaldo (solo las que suelen aceptar JSON; se prueban en orden).
   Si ninguna responde, el robot salta a DDG. Configura SEARXNG_URL en Render para fijar una. */
const SEARXNG_FALLBACK = [
    'https://searx.be',
    'https://priv.au',
    'https://searx.tiekoetter.com',
];

const RATE_MAX    = 24;            // peticiones por ventana (por sesión)
const RATE_WINDOW = 60;            // segundos
const MAX_INPUT   = 600;
const MAX_REPLY   = 1800;

const DIR_BASE   = __DIR__ . '/..';
const F_GLOSARIO = DIR_BASE . '/glosarios/APSARA_VIMANA_GLOSARIO_MAESTRO_UNIFICADO_1.txt';
const F_BIBLIO_H = DIR_BASE . '/biblioteca.html';
const DIR_CACHE  = DIR_BASE . '/cache';

/* Asegurar existencia de carpeta cache */
if (!is_dir(DIR_CACHE)) {
    @mkdir(DIR_CACHE, 0755, true);
}

/* Imágenes de estados — rutas oficiales confirmadas */
const IMG = [
    'base'         => 'imagenes/estados/2.jpg',
    'atento'       => 'imagenes/estados/Atento.jpg',
    'pensando'     => 'imagenes/estados/Pensando.jpg',
    'reconectando' => 'imagenes/estados/Reconectando.jpg',
    'restablecido' => 'imagenes/estados/Restablecido.jpg',
    'promover'     => 'imagenes/estados/promover.jpg',
    'gratitud'     => 'imagenes/estados/gratitud.jpg',
    'sorprendido'  => 'imagenes/estados/Sorprendido.jpg',
    'suspendido'   => 'imagenes/estados/suspendido.jpg',
    'buscando'     => 'imagenes/estados/avatar.jpg',   // SOLO con búsqueda externa
    'logo'         => 'imagenes/estados/logo.png',
];

/* Maestros — una entidad, una identidad, múltiples alias. */
const PERSONAS = [
    'sathya_sai_baba' => [
        'nombres' => [
            'sathya sai baba', 'sathia sai baba', 'satya sai baba', 'sathi sai baba',
            'sathya sai', 'sathia sai', 'satya sai', 'sathi sai',
            'sri sathya sai baba', 'sri sathia sai baba', 'sri satya sai baba',
            'sathya', 'sathia', 'satya', 'sathi',
            'sai baba de puttaparthi', 'sai baba puttaparthi',
        ],
        'imagen'  => 'imagenes/personas/Sathya Sai Baba.jpg',
        'quien'   => 'Sri Sathya Sai Baba (1926–2011): considerado por millones de seguidores como un Avatar moderno y una encarnación del amor divino. Su enseñanza se resume en: "Ama a todos, sirve a todos" y "Ayuda siempre, no hieras nunca". Enfatizó el cultivo de los cinco valores humanos (Verdad, Rectitud, Paz, Amor y No Violencia) y el servicio desinteresado a la comunidad.',
        'sitios'  => [['Sri Sathya Sai International Organization', 'https://www.sathyasai.org/'],
                      ['SSSIO USA', 'https://sathyasai.us/']],
    ],

    'yogananda' => [
        'nombres' => ['yogananda', 'paramahansa yogananda', 'paramhansa yogananda'],
        'imagen'  => 'imagenes/personas/Yogananda.jpg',
        'quien'   => 'Paramahansa Yogananda (1893–1952): pionero del Yoga en Occidente y autor de "Autobiografía de un yogui". Reveló la armonía entre la meditación de Oriente (Kriya Yoga) y las enseñanzas místicas de Occidente, guiando a las almas hacia la unión directa con Dios.',
        'sitios'  => [['Self-Realization Fellowship', 'https://yogananda.org/']],
    ],
    'sadhguru' => [
        'nombres' => [
            'sadhguru', 'sadh guru', 'sad guru', 'sadguru', 'sadh-guru',
            'jaggi vasudev', 'jaggi', 'isha foundation',
        ],
        'imagen'  => 'imagenes/personas/sadh_guru.jpg',
        'quien'   => 'Sadhguru (Jaggi Vasudev, 1957– ): yogui, místico y fundador de la Fundación Isha. Promueve el "Inner Engineering" como tecnología interior para el bienestar físico, mental y energético, además de campañas ecológicas globales.',
        'sitios'  => [['Isha Foundation', 'https://isha.sadhguru.org/in/en']],
    ],
    'ravi_shankar' => [
        'nombres' => [
            'ravi shankar', 'sri sri ravi shankar', 'sri sri', 'arte de vivir',
            'art of living', 'ravishankar',
        ],
        'imagen'  => 'imagenes/personas/ravi_shankar.jpg',
        'quien'   => 'Sri Sri Ravi Shankar (1956– ): líder humanitario y maestro espiritual, fundador de El Arte de Vivir. Aboga por una sociedad libre de estrés y violencia; su herramienta principal es el Sudarshan Kriya, técnica de respiración que armoniza las emociones y calma la mente.',
        'sitios'  => [['The Art of Living', 'https://www.artofliving.org/in-en']],
    ],
    'ramana' => [
        'nombres' => [
            'ramana', 'ramana maharshi', 'ramana maharsi', 'ramana maharishi',
            'maharshi ramana', 'bhagavan ramana',
        ],
        'imagen'  => 'imagenes/personas/ramana_maharsh.jpg',
        'quien'   => 'Ramana Maharshi (1879–1950): sabio advaita del siglo XX, vivió en silencio contemplativo a los pies de Arunachala. Enseñó la vía directa del Atma-Vichara, la Autoindagación: preguntarse en silencio "¿Quién soy yo?" para disolver la ilusión del ego.',
        'sitios'  => [['Arunachala Ashrama USA', 'https://arunachala.org/']],
    ],
    'prabhupada' => [
        'nombres' => [
            'prabhupada', 'srila prabhupada', 'bhaktivedanta', 'iskcon',
            'hare krishna', 'a c bhaktivedanta',
        ],
        'imagen'  => 'imagenes/personas/Prabhupada.jpg',
        'quien'   => 'Srila Prabhupada (1896–1977): maestro gaudiya vaishnava, fundador del Movimiento Hare Krishna (ISKCON). Difundió el Bhakti Yoga, el yoga del amor desinteresado y la devoción, mediante el canto del Maha-Mantra y el servicio devocional.',
        'sitios'  => [['ISKCON Official', 'https://iskcon.org/'],
                      ['Prabhupada Net', 'https://www.prabhupada.net/']],
    ],
    'osho' => [
        'nombres' => ['osho', 'rajneesh', 'bhagwan shree rajneesh', 'bhagwan rajneesh'],
        'imagen'  => 'imagenes/personas/osho.jpg',
        'quien'   => 'Osho (1931–1990): místico y filósofo contemporáneo. Rechazó los dogmas rígidos y diseñó las Meditaciones Dinámicas para liberar las tensiones de la vida moderna, proponiendo una presencia profunda y celebración de la existencia.',
        'sitios'  => [['OSHO – Science of Meditation', 'https://www.osho.com/']],
    ],
    'ramakrishna' => [
        'nombres' => [
            'ramakrishna', 'ramakrishna paramahansa', 'sri ramakrishna',
            'rama krishna', 'paramahansa ramakrishna',
        ],
        'imagen'  => 'imagenes/personas/Amahansa sobre Rama Krishna.jpg',
        'quien'   => 'Sri Ramakrishna Paramahansa (1836–1886): místico bengalí cuya devoción extática inspiró el renacimiento espiritual moderno de la India; maestro de Swami Vivekananda. Afirmó la unidad fundamental de todas las religiones: "El fin principal de la vida humana es la realización de Dios".',
        'sitios'  => [['Belur Math', 'https://belurmath.org/'],
                      ['Sri Ramakrishna', 'https://sriramakrishna.in/']],
    ],
];

/**
 * Empareja un maestro de PERSONAS aunque el usuario escriba con espacios o variantes
 * (ej. "sadh guru" ≈ "sadhguru", "sad guru").
 * Devuelve la clave PERSONAS o null.
 */
function match_persona(string $n): ?string {
    $n_compact = preg_replace('/\s+/u', '', $n); // "sadh guru" → "sadhguru"
    foreach (PERSONAS as $key => $p) {
        foreach ($p['nombres'] as $nombre) {
            $nn = norm($nombre);
            if ($nn === '') continue;
            if (strpos($n, $nn) !== false) return $key;
            $nn_c = preg_replace('/\s+/u', '', $nn);
            if ($nn_c !== '' && strpos($n_compact, $nn_c) !== false) return $key;
        }
    }
    return null;
}

/* Álbumes y recursos Vimana Music */
const ALBUMES = [
    'ananda' => [
        'nombres' => ['ananda', 'sat chit ananda', 'satchitananda'],
        'titulo'  => 'Sat Chit Ananda (Álbum ANANDA)',
        'imagen'  => 'imagenes/albunes/SatChitAnanda.png',
        'url'     => 'https://vimana.rf.gd/SatChitAnanda.html',
        'en_vimana' => true,
        'estilo'  => 'Música de Vimana: cantos y temas espirituales propios del proyecto (escuchar en vimana.rf.gd).',
        'temas'   => ['Sat Chit Ananda (inglés)', 'Sat Chit Ananda — Dicha Suprema', 'De la Tercera a la 5ª Dimensión + 5ª Frecuencia', 'Aham Brahmasmi', 'Sat Chit Ananda (Español)', 'Shivo Ham, Uno con El Supremo', 'Ayam Atma Brahma', 'Despertamos al Amanecer Dorado', 'Nueva Era Dorada — El Retorno 1', 'Nueva Era Dorada — El Retorno 2'],
    ],
    'divinaversum' => [
        'nombres' => ['divinaversum', 'divina versum'],
        'titulo'  => 'Divinaversum',
        'imagen'  => 'imagenes/albunes/divinaversum.png',
        'url'     => 'https://vimana.rf.gd/SatChitAnanda2.html',
        'en_vimana' => true,
        'estilo'  => 'Música de Vimana: álbum propio del sitio (escuchar en vimana.rf.gd).',
        'temas'   => ['Un mundo una familia somos uno', 'One world one family', 'Lokah samastah sukhino bhavantu', 'Los cantos sagrados nos unen', 'Somos uno con la luz divina', 'Despertamos juntos al amanecer dorado', 'Unidos en amor', 'En mi corazón tu gracia vivirá', 'El Salvador luz de naciones'],
    ],
    'soham' => [
        'nombres' => ['so ham', 'soham', 'so han', 'sohan'],
        'titulo'  => 'SO HAM',
        'imagen'  => 'imagenes/albunes/sohan.jpg',
        'url'     => 'https://vimana.rf.gd/SOHAM.html',
        'en_vimana' => true,
        /* NO es meditación instrumental ni clásica india: es música moderna devocional hindu-latina, cantada */
        'estilo'  => 'Música moderna devocional hindu-latina (canto). Está en Vimana. No es estilo clásico indio ni “música para meditar” instrumental.',
        'temas'   => ['SO HAM', 'Soy la forma de la paz', 'Sri Ram, Jey Jey Sai Ram — Luz del Nuevo Sol', 'Brahma Murari Tripurantakari', 'Somos Uno (Cantos eternos de paz y amor)', 'Om Sri Laksmy Kuvera Namaha', 'Guru Purnima 1', 'Guru Purnima 2'],
    ],
    'sadhana' => [
        'nombres' => ['sadhana'],
        'titulo'  => 'Sadhana — Práctica evolutiva online',
        'imagen'  => 'imagenes/albunes/sadhana.png',
        'url'     => 'https://vimana.rf.gd/mayo2026/index.html',
        'en_vimana' => true,
        'estilo'  => 'Recurso de Vimana: práctica / contenido online en el sitio.',
        'temas'   => ['La sadhana es una disciplina o práctica espiritual diaria y consciente, realizada con constancia para alcanzar el autoconocimiento, la autorrealización o la liberación del sufrimiento.'],
    ],
    'podcast' => [
        'nombres' => ['podcast', 'podcast vimana', 'charlas vimana'],
        'titulo'  => 'Vimana Podcast',
        'imagen'  => 'imagenes/albunes/podcast.png',
        'url'     => 'https://vimana.rf.gd/podcastvimana.html',
        'en_vimana' => true,
        'estilo'  => 'Podcast de Vimana (charlas y sabiduría) — en el sitio vimana.rf.gd.',
        'temas'   => ['Conexión Sabiduría', 'Maha Shivaratri — Frecuencias Evolutivas', 'Derrumbe de Paradigmas — Despertar entrada de acuario', 'La verdadera revolución es interna', 'La Verdad Te Libera', 'Manifestación Divina', 'Deja de Curarte', 'Del Quantum al Purana', 'Ya eres lo que buscas', 'El Arte de la Rendición Divina', 'Guru Purnima 2026', 'Sol es la Vida y la Conciencia (Gayatri)', 'Lo que todos deberíamos saber sobre nuestra paz', '¿Quién soy cuando desaparece el "yo"?'],
    ],
    'relax' => [
        'nombres' => ['relax', 'relajacion profunda', 'musica para dormir', 'dormir y descansar'],
        'titulo'  => 'Música Relajante para Dormir y Descansar',
        'imagen'  => 'imagenes/albunes/relax.png',
        'url'     => 'https://vimana.rf.gd/fb.html',
        'en_vimana' => true,
        'estilo'  => 'Música de Vimana para descanso (lluvia, zen, cuencos) — en el sitio.',
        'temas'   => ['Música Relajante para Dormir y Descansar', 'Sonido de Lluvia y Relajación Profunda', 'Música Zen para Sanación Cuántica y Estrés', 'Música de Cuencos Tibetanos y Campanas', 'Música Zen para Meditar — Equilibrio y Paz Mental', 'AntiEstrés — Suave (música para masajes, spa, bienestar)'],
    ],
    'radio' => [
        'nombres' => ['radio', 'radio online', 'radio vimana'],
        'titulo'  => 'Radio Online Vimana',
        'imagen'  => null,
        'url'     => 'https://vimana.rf.gd/radio.html',
        'en_vimana' => true,
        'estilo'  => 'Radio online de Vimana.',
        'temas'   => ['Radio online de Vimana Media Player.'],
    ],
];

/* Valores Humanos */
const VALORES = [
    ['sathya',  'Verdad (Sathya)', 'Representa la búsqueda de lo inmutable y la coherencia interior. Corresponde al nivel intelectual superior.',
     ['Autenticidad','Autoanálisis','Autoconocimiento','Búsqueda del conocimiento','Coherencia','Curiosidad noble','Discernimiento (Viveka)','Espíritu de indagación','Exactitud','Fe','Honestidad','Intuición','Objetividad','Razonamiento elevado','Reflexión','Sentido de la realidad','Sinceridad','Síntesis','Veracidad']],
    ['dharma',  'Rectitud / Acción Correcta (Dharma)', 'Representa la verdad puesta en acción. Corresponde al nivel físico y al deber con el entorno.',
     ['Autoconfianza','Autosuficiencia','Buen comportamiento','Deber (Kartavya)','Disciplina','Ética','Integridad','Justicia','Lealtad','Moralidad','Orden','Perseverancia','Responsabilidad','Sentido del deber']],
    ['shanti',  'Paz (Shanti)', 'Representa el estado mental en calma y el dominio de los sentidos. Corresponde al nivel emocional.',
     ['Atención','Autoaceptación','Autocontrol','Autodisciplina','Autorespeto','Armonía','Calma','Equilibrio','Paciencia','Serenidad','Silencio interior','Tolerancia','Tranquilidad','Unidad']],
    ['prema',   'Amor (Prema)', 'Representa el flujo de energía incondicional que une a la creación. Es la base espiritual de la existencia.',
     ['Agradecimiento','Alegría','Amabilidad','Amistad','Ayuda / Apoyo','Belleza','Bienaventuranza','Bondad','Compasión','Empatía','Generosidad','Gratitud','Ternura','Unicidad']],
    ['ahimsa',  'No Violencia (Ahimsa)', 'La suma total y máxima expresión de todos los valores humanos. Corresponde al entendimiento de la Unidad de la creación.',
     ['Amor universal','Buenos modales','Ciudadanía consciente','Consideración','Cooperación','Fraternidad','Gentileza','Hospitalidad','Respeto','Servicio desinteresado','Tacto','Unidad']],
];

/* Necesidades musicales */
const NECESIDADES = [
    ['estudiar|estudio|concentrar|concentracion|concentrarme|trabajar|trabajo|leer', 'ESTUDIO / CONCENTRACIÓN',
     'Para estudiar y concentrarte, lo más adecuado es música instrumental sin letra: música clásica, ambient y jazz instrumental suave. También Sitar, Bansuri y Santoor, cuyas melodías meditativas ordenan la mente.'],
    ['meditar|meditacion|mindfulness|contemplar', 'MEDITACIÓN',
     'Para meditar: Bansuri, Sitar y Santoor, mantras y música ambiental o Zen. La música clásica instrumental de la India (Hindustaní o Carnática) está diseñada precisamente para la improvisación meditativa.'],
    ['relajar|relajacion|calmar|calma|estres|estrés|ansiedad', 'RELAJACIÓN',
     'Para relajarte: Bansuri, sonidos de la naturaleza, música ambient, música Zen y cuencos tibetanos. Sonidos suaves que bajan el ritmo mental.'],
    ['dormir|sueño|sueno|descansar|insomnio', 'DORMIR',
     'Para dormir: música lenta, sonidos de lluvia, naturaleza, ambient y música relajante en general.'],
    ['practica espiritual|bhajan|bhajans|devocion|devocional|oracion|oración', 'PRÁCTICA ESPIRITUAL',
     'Para la práctica espiritual y devocional, los bhajans (cantos devocionales) son el recurso tradicional por excelencia.'],
];

/* Redes y canales de Vimana. */
const REDES = [
    ['Canal de WhatsApp', 'https://whatsapp.com/channel/0029Vb7JnG2LtOj4YBb16905'],
    ['Facebook', 'https://web.facebook.com/vimana.oficial.sv'],
    ['TikTok', 'https://tiktok.com/@vimanasv2'],
    ['Instagram', 'https://instagram.com/vimana.oficial.sv'],
    ['Telegram', 'https://t.me/s/VimanaSabiduria'],
    ['Sitio web oficial', 'https://vimana.rf.gd/'],
];

/* ----------------------------------------------------------------------------
   2. UTILIDADES Y PETICIONES HTTP
   ---------------------------------------------------------------------------- */

function salida(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function norm(string $t): string {
    $t = mb_strtolower($t, 'UTF-8');
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        if ($conv !== false) $t = $conv;
    }
    $t = preg_replace('/[^\pL\pN\s]/u', ' ', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim($t);
}

function esc(string $t): string {
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $t));
}

function recortar(string $t, int $max = MAX_REPLY): string {
    if (mb_strlen($t, 'UTF-8') <= $max) return $t;
    return mb_substr($t, 0, $max - 1, 'UTF-8') . '…';
}

function respuesta(string $reply, ?string $img = null, array $links = [], array $options = [], ?string $state = null): array {
    return [
        'ok'      => true,
        'agente'  => AGENTE,
        'reply'   => recortar($reply),
        'image'   => $img,
        'links'   => $links,
        'options' => $options,
        'state'   => $state,
    ];
}

function url_img(string $ruta): string {
    return implode('/', array_map('rawurlencode', explode('/', $ruta)));
}

function http_get(string $url): ?string {
    if (!function_exists('curl_init')) { $GLOBALS['red_fallo'] = true; return null; }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => EXT_TIMEOUT,
        CURLOPT_USERAGENT      => 'ApsaraVimanaBot/1.0 (https://vimana.rf.gd/; contacto@vimana.rf.gd)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res !== false && $code === 200) return $res;
    if ($res === false) $GLOBALS['red_fallo'] = true;
    return null;
}

/* ----------------------------------------------------------------------------
   3. RATE LIMIT POR SESIÓN
   ---------------------------------------------------------------------------- */

function rate_limit(): bool {
    $ahora = time();
    $ventana = &$_SESSION['apsara_rate'];
    if (!is_array($ventana)) $ventana = [];
    $ventana = array_values(array_filter($ventana, fn($t) => $ahora - $t < RATE_WINDOW));
    if (count($ventana) >= RATE_MAX) return false;
    $ventana[] = $ahora;
    return true;
}

/* ----------------------------------------------------------------------------
   4. LIMPIEZA PERIÓDICA DE CACHÉ
   ---------------------------------------------------------------------------- */

function limpieza_cache(): void {
    if (mt_rand(1, 40) !== 1) return;
    $ahora = time();
    foreach (glob(DIR_CACHE . '/ext_*.json') ?: [] as $f) {
        if (is_file($f) && ($ahora - (int)@filemtime($f)) > 86400 * 7) {
            @unlink($f);
        }
    }
    $archivos = glob(DIR_CACHE . '/ext_*.json') ?: [];
    if (count($archivos) > 500) {
        usort($archivos, fn($a, $b) => (int)@filemtime($a) <=> (int)@filemtime($b));
        foreach (array_slice($archivos, 0, count($archivos) - 500) as $viejo) {
            @unlink($viejo);
        }
    }
}

/* ----------------------------------------------------------------------------
   5. CONOCIMIENTO LOCAL
   ---------------------------------------------------------------------------- */

/**
 * Carga el glosario desde APSARA_VIMANA_GLOSARIO_MAESTRO_UNIFICADO_1.txt
 * Formato principal: TÉRMINO | alias | categoría | definición
 * También acepta: Término: definición
 * Omite secciones de INSTRUCCIONES / reglas internas (nunca se indexan).
 * Caché: cache/glosario_index.json (se regenera si cambia el TXT).
 */
function cargar_glosario(): array {
    $cache = DIR_CACHE . '/glosario_index.json';
    $mt = @filemtime(F_GLOSARIO);
    if ($mt && file_exists($cache)) {
        $j = json_decode((string)@file_get_contents($cache), true);
        if (is_array($j) && ($j['mt'] ?? 0) === $mt && !empty($j['idx'])) {
            return $j['idx'];
        }
    }
    $idx = [];
    if (!file_exists(F_GLOSARIO)) return [];
    $lineas = file(F_GLOSARIO, FILE_IGNORE_NEW_LINES);
    if (!$lineas) return [];

    $basura = [
        'pregunta', 'respuesta', 'enlace', 'fin', 'nota', 'objetivo', 'importante',
        'formato', 'formato de entrada', 'proposito', 'propósito', 'reglas para el agente',
        'instrucciones', 'instrucciones para agentes de ia', 'instrucciones internas',
        'instrucciones tecnicas', 'instrucciones técnicas', 'archivo unico', 'archivo único',
    ];
    $omitir = false; // dentro de bloque de instrucciones

    foreach ($lineas as $l) {
        $ls = trim($l);
        if ($ls === '') continue;

        // Detectar títulos de sección
        if (preg_match('/^={5,}/', $ls)) continue;
        if (preg_match('/^\[ [A-ZÁÉÍÓÚÑ] \]$/u', $ls)) continue;

        // Omite solo bloques claramente técnicos / instrucciones internas
        $up = mb_strtoupper($ls, 'UTF-8');
        if (strpos($up, 'INSTRUCCIONES INTERNAS') !== false
            || strpos($up, 'INSTRUCCIONES TÉCNICAS') !== false
            || strpos($up, 'INSTRUCCIONES TECNICAS') !== false
            || strpos($up, 'NO MOSTRAR ESTE ARCHIVO') !== false
            || strpos($up, 'INSTRUCCIONES PARA ENGINE') !== false
            || (strpos($up, 'INSTRUCCIONES PARA EL AGENTE') !== false && strpos($up, 'IA LOCAL') !== false)
            || strpos($up, 'LÓGICA DE RECOMENDACIÓN PARA ENGINE') !== false
            || strpos($up, 'LOGICA DE RECOMENDACION PARA ENGINE') !== false) {
            $omitir = true;
            continue;
        }
        if ($omitir && (
            preg_match('/^(I{1,3}|IV|V|VI|VII|VIII|IX|X|XI)\.\s/u', $ls)
            || preg_match('/^GLOSARIO/ui', $ls)
            || preg_match('/^VALORES HUMANOS/ui', $ls)
            || preg_match('/^MAESTROS/ui', $ls)
            || preg_match('/^RECURSOS OFICIALES/ui', $ls)
            || preg_match('/^BASE DE RECOMENDACIONES/ui', $ls)
            || preg_match('/^PERSONAJES/ui', $ls)
            || preg_match('/^FIN DE /ui', $ls)
        )) {
            // FIN DE ... cierra bloque; siguiente contenido útil se reabre
            if (preg_match('/^FIN DE /ui', $ls)) { $omitir = false; continue; }
            $omitir = false;
        }
        if ($omitir) continue;
        // No indexar líneas de reglas operativas
        if (preg_match('/^(SI LA PREGUNTA|SI EL USUARIO|EL ENGINE DEBE|APSARA DEBE|NO DEBE|DEBE:|PASO \d)/ui', $ls)) continue;

        // Formato pipe: TÉRMINO | alias | cat | def  (mínimo 2 campos)
        if (strpos($ls, '|') !== false) {
            $parts = array_map('trim', explode('|', $ls));
            if (count($parts) < 2) continue;
            $termino = $parts[0];
            // Saltar cabeceras de tabla
            $tn = mb_strtolower($termino, 'UTF-8');
            if (in_array($tn, ['término', 'termino', 'nombre', 'tipo', 'artista', 'formato', 'uso', 'categoría', 'categoria'], true)) {
                continue;
            }
            /* Nunca indexar ejemplos del agente ni plantillas (salen como “definición” falsa) */
            if (preg_match('/^quiero\b/ui', $termino)) continue;
            if (preg_match('/^usuario\b/ui', $termino)) continue;
            if (preg_match('/^(si |cuando |el engine|apsara debe|no debe|debe |pregunta:|respuesta:)/ui', $termino)) continue;
            // Definición = último campo no vacío; si hay categoría, suele ser el 4º
            $def = '';
            for ($i = count($parts) - 1; $i >= 1; $i--) {
                if ($parts[$i] !== '' && $parts[$i] !== '—') {
                    $def = $parts[$i];
                    break;
                }
            }
            if ($termino === '' || $def === '' || mb_strlen($def, 'UTF-8') < 8) continue;
            // Evitar indexar líneas que son reglas largas
            if (preg_match('/^(SI |CUANDO |EL ENGINE|APSARA DEBE|NO DEBE|DEBE )/ui', $def)) continue;
            if (preg_match('/^quiero\b/ui', $def)) continue;

            $keys = [$termino];
            // alias en campo 2
            if (!empty($parts[1]) && $parts[1] !== '—' && $parts[1] !== '-') {
                foreach (preg_split('/[;\/,]/u', $parts[1]) as $al) {
                    $al = trim($al);
                    if ($al !== '') $keys[] = $al;
                }
            }
            foreach ($keys as $k) {
                $key = norm($k);
                if ($key === '' || mb_strlen($key, 'UTF-8') < 2) continue;
                if (in_array($key, $basura, true)) continue;
                if (!isset($idx[$key]) || mb_strlen($def, 'UTF-8') > mb_strlen($idx[$key], 'UTF-8')) {
                    $idx[$key] = $def;
                }
            }
            continue;
        }

        // Formato clásico: Término: definición
        if (preg_match('/^([A-ZÁÉÍÓÚÜÑa-záéíóúüñ][^:]{1,100}):\s+(.+)$/u', $ls, $m)) {
            $termino = trim($m[1], " \t\"'“”");
            $def = trim($m[2]);
            if ($termino === '' || $def === '' || mb_strlen($def, 'UTF-8') < 8) continue;
            if (preg_match('/^(SI |CUANDO |EL ENGINE|APSARA DEBE)/ui', $def)) continue;
            foreach (preg_split('/[\/;]/u', $termino) as $alias) {
                $a = trim($alias);
                $key = norm($a);
                if ($key === '' || in_array($key, $basura, true)) continue;
                if (!isset($idx[$key])) $idx[$key] = $def;
            }
        }
    }

    if ($mt) {
        // Caché compacto: solo idx + mt (sin saturar)
        @file_put_contents($cache, json_encode(['mt' => $mt, 'n' => count($idx), 'idx' => $idx], JSON_UNESCAPED_UNICODE));
    }
    return $idx;
}

function cargar_biblioteca(): array {
    $cache = DIR_CACHE . '/biblioteca_index.json';
    $mt = @filemtime(F_BIBLIO_H);

    /* 1) Intentar parsear biblioteca.html (fuente de verdad) */
    $html = (string)@file_get_contents(F_BIBLIO_H);
    $idx = [];
    if ($html !== '') {
        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $url = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                $titulo = trim(preg_replace('/\s+/u', ' ', strip_tags($m[2])));
                $titulo = html_entity_decode($titulo, ENT_QUOTES, 'UTF-8');
                if ($titulo === '' || !preg_match('#^https?://#i', $url)) continue;
                if (isset($idx[$titulo])) continue;
                $idx[$titulo] = $url;
            }
        }
        if ($idx) {
            @file_put_contents($cache, json_encode(['mt' => $mt ?: time(), 'idx' => $idx], JSON_UNESCAPED_UNICODE));
            return $idx;
        }
    }

    /* 2) Fallback: caché pre-generado (227 títulos del HTML de categorías) */
    if (file_exists($cache)) {
        $j = json_decode((string)@file_get_contents($cache), true);
        if (is_array($j) && !empty($j['idx']) && is_array($j['idx'])) {
            return $j['idx'];
        }
    }
    return [];
}

function buscar_en(array $idx, string $norm_q, int $min = 45): array {
    $tokens = array_values(array_filter(explode(' ', $norm_q), fn($t) => mb_strlen($t, 'UTF-8') >= 3));
    if (!$tokens) return [null, 0];
    $mejor = null; $puntaje = 0;
    foreach ($idx as $clave => $valor) {
        $n_clave = norm((string)$clave);
        if ($n_clave === '' || mb_strlen($n_clave, 'UTF-8') < 2) continue;
        if ($n_clave === $norm_q) return [(string)$clave, 100];
        $p = 0;
        $clave_en_q = (bool)preg_match('/(?:^|\s)' . preg_quote($n_clave, '/') . '(?:$|\s)/u', $norm_q);
        $q_en_clave = (bool)preg_match('/(?:^|\s)' . preg_quote($norm_q, '/') . '(?:$|\s)/u', $n_clave);
        if ($clave_en_q || $q_en_clave) {
            $p = 80 + min(15, mb_strlen($n_clave, 'UTF-8'));
        } else {
            $ct = 0;
            foreach ($tokens as $t) {
                if (preg_match('/(?:^|\s)' . preg_quote($t, '/') . '(?:$|\s)/u', $n_clave)) $ct++;
            }
            if ($ct) {
                $p = (int)round(50 * $ct / max(1, count(explode(' ', $n_clave))));
            }
        }
        if (mb_strlen($n_clave, 'UTF-8') <= 3 && $p > 0) {
            if (!$clave_en_q) { $p = 0; }
            elseif (count($tokens) > 4) { $p = min($p, 40); }
        }
        if ($p > $puntaje) { $puntaje = $p; $mejor = (string)$clave; }
    }
    return [$mejor, $puntaje >= $min ? $puntaje : 0];
}

function limpiar_def(string $def): string {
    $def = trim($def);
    if (preg_match('/\s*\[\s*[A-Z]\s*\]\s+/u', $def, $m, PREG_OFFSET_CAPTURE)) {
        $def = trim(substr($def, 0, $m[0][1]));
    }
    if (preg_match('/\s+(PREGUNTA:|RESPUESTA:|ENLACE:|#{1,3}\s)/u', $def, $m, PREG_OFFSET_CAPTURE)) {
        $def = trim(substr($def, 0, $m[0][1]));
    }
    return $def;
}

/* ----------------------------------------------------------------------------
   6. BÚSQUEDA EXTERNA (Wikipedia y DuckDuckGo)
   ---------------------------------------------------------------------------- */

function limpiar_consulta_busqueda(string $query): string {
    $q = preg_replace('/\b(busca|buscar|buscame|búscame|pagina de|página de|paginas sobre|páginas sobre|links?|enlaces?|sobre|internet|en la web)\b/ui', ' ', $query);
    $q = trim(preg_replace('/\s+/u', ' ', $q));
    return $q !== '' ? $q : $query;
}

function reescribir_consulta_wiki(string $query): string {
    $n = norm($query);
    if (preg_match('/\b(muy\s*linux|muylinux)\b/u', $n)) return 'MuyLinux';
    if (preg_match('/\b(vegano|veganos|vegana|veganismo)\b/u', $n)) return 'Veganismo';
    if (preg_match('/\b(vegetariano|vegetariana|vegetarianismo)\b/u', $n)) return 'Vegetarianismo';
    if (preg_match('/\b(robotica|robótica)\b/u', $n)) return 'Robótica';
    if (preg_match('/\b(cuantica|cuántica|quantum)\b/u', $n)) return 'Física cuántica';
    if (preg_match('/\b(ayurveda|ayurvedica|ayurvédica)\b/u', $n)) return 'Ayurveda';
    return limpiar_consulta_busqueda($query);
}

function url_yahoo_search(string $query): string {
    return 'https://search.yahoo.com/search?p=' . urlencode(limpiar_consulta_busqueda($query));
}

function buscar_wikipedia(string $query): ?array {
    $query_wiki = reescribir_consulta_wiki($query);
    $url = WIKI_API_URL . '?action=query&format=json&prop=extracts&exintro=1&explaintext=1'
         . '&redirects=1&generator=search&gsrlimit=1&gsrsearch=' . urlencode($query_wiki);
    $res = http_get($url);
    if ($res === null) return null;
    $d = json_decode($res, true);
    if (empty($d['query']['pages'])) return null;
    $page = reset($d['query']['pages']);
    if (empty($page['extract'])) return null;
    $extract = trim($page['extract']);
    if (mb_strlen($extract, 'UTF-8') > 900) {
        $extract = mb_substr($extract, 0, 900, 'UTF-8') . '…';
    }
    $u = 'https://es.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', (string)$page['title']));
    return [
        'texto'  => "Encontré esto en Wikipedia:\n\n" . $extract,
        'fuente' => 'Wikipedia',
        'url'    => $u,
        'titulo' => (string)$page['title'],
        'links'  => [['t' => 'Leer más en Wikipedia', 'u' => $u]],
    ];
}

function buscar_duckduckgo(string $query): ?array {
    $q = limpiar_consulta_busqueda($query);
    $url = 'https://api.duckduckgo.com/?q=' . urlencode($q) . '&format=json&no_html=1&skip_disambig=1';
    $res = http_get($url);
    if ($res === null) return null;
    $d = json_decode($res, true);
    if (!is_array($d)) return null;

    $texto = trim((string)($d['AbstractText'] ?? ''));
    $urlRes = trim((string)($d['AbstractURL'] ?? ''));

    if ($texto === '' && !empty($d['RelatedTopics']) && is_array($d['RelatedTopics'])) {
        foreach ($d['RelatedTopics'] as $rt) {
            if (!empty($rt['Text']) && !empty($rt['FirstURL'])) {
                $texto = trim($rt['Text']);
                $urlRes = trim($rt['FirstURL']);
                break;
            }
        }
    }

    if ($texto === '') return null;

    if (mb_strlen($texto, 'UTF-8') > 900) {
        $texto = mb_substr($texto, 0, 900, 'UTF-8') . '…';
    }

    $links = [];
    if ($urlRes !== '') {
        $links[] = ['t' => 'Ver fuente', 'u' => $urlRes];
    } else {
        $u_ddg = 'https://duckduckgo.com/?q=' . urlencode($q);
        $links[] = ['t' => 'Buscar en DuckDuckGo', 'u' => $u_ddg];
    }

    return [
        'texto'  => "Encontré esto en DuckDuckGo:\n\n" . $texto,
        'fuente' => 'DuckDuckGo',
        'url'    => $urlRes,
        'titulo' => $q,
        'links'  => $links,
    ];
}

/**
 * SearXNG (metabuscador gratis). Solo si hay URL configurada o fallback responde JSON.
 * No bloquea el flujo: fallo → null y sigue a DDG.
 */
function buscar_searxng(string $query): ?array {
    $q = limpiar_consulta_busqueda($query);
    if ($q === '') return null;

    $bases = [];
    $env = trim((string)(getenv('SEARXNG_URL') ?: ''));
    if ($env !== '') {
        $bases[] = rtrim($env, '/');
    }
    foreach (SEARXNG_FALLBACK as $u) {
        $u = rtrim($u, '/');
        if ($u !== '' && !in_array($u, $bases, true)) {
            $bases[] = $u;
        }
    }
    if ($bases === []) return null;

    foreach (array_slice($bases, 0, 3) as $base) {
        $url = $base . '/search?q=' . urlencode($q) . '&format=json&language=es-ES&pageno=1';
        $res = http_get($url);
        if ($res === null) continue;
        $d = json_decode($res, true);
        if (!is_array($d) || empty($d['results']) || !is_array($d['results'])) continue;

        $lineas = [];
        $links  = [];
        $i = 0;
        foreach ($d['results'] as $r) {
            if ($i >= 5) break;
            $title = trim((string)($r['title'] ?? ''));
            $href  = trim((string)($r['url'] ?? $r['pretty_url'] ?? ''));
            $snip  = trim((string)($r['content'] ?? $r['snippet'] ?? ''));
            if ($title === '' || $href === '' || !preg_match('#^https?://#i', $href)) continue;
            $i++;
            $lineas[] = "{$i}. **{$title}**\n   " . ($snip !== '' ? mb_substr($snip, 0, 160, 'UTF-8') : '') . "\n   🔗 {$href}";
            $links[]  = ['t' => $title, 'u' => $href];
        }
        if ($lineas === []) continue;

        $texto = "🔍 Resultados de la búsqueda para: «{$q}»\n\n" . implode("\n\n", $lineas);
        return [
            'texto'  => $texto,
            'fuente' => 'SearXNG',
            'url'    => $base . '/search?q=' . urlencode($q),
            'titulo' => $q,
            'links'  => $links,
        ];
    }
    return null;
}

/**
 * Temas que NO deben resolverse con Wikipedia primero:
 * actualidad, ciencia aplicada, software, medicina, alimentos, astrología, etc.
 * Wikipedia queda siempre como ÚLTIMO recurso (o se omite en tiempo real).
 */
function es_consulta_no_wiki_primero(string $n): bool {
    /* Actualidad / tiempo real */
    if (preg_match('/\b(cartelera|horario|horarios|programacion|programación|esta semana|esta noche|hoy|manana|mañana|ahora|en vivo|resultado|resultados|marcador|fixture|calendario deportivo)\b/u', $n)) return true;
    if (preg_match('/\b(noticia|noticias|actualidad|ultimas noticias|últimas noticias|breaking)\b/u', $n)) return true;
    if (preg_match('/\b(clima|tiempo|pronostico|pronóstico|temperatura)\b/u', $n)) return true;
    if (preg_match('/\b(deportes?|futbol|fútbol|nba|mlb|nfl|ufc|boxeo|tenis|formula\s*1|f1)\b/u', $n)) return true;
    /* Software / tecnología */
    if (preg_match('/\b(software|linux|ubuntu|debian|windows|android|iphone|ios|app|aplicacion|aplicación|codigo|código|programacion|programación|python|javascript|php|html|css|github|descargar|instalar|error|bug)\b/u', $n)) return true;
    /* Ciencia */
    if (preg_match('/\b(ciencia|cientifico|científico|fisica|física|quimica|química|biologia|biología|astronomia|astronomía|cuantica|cuántica|quantum|espacio|nasa|planeta|galaxia)\b/u', $n)) return true;
    /* Astrología / esoterismo popular */
    if (preg_match('/\b(astrologia|astrología|horoscopo|horóscopo|zodiaco|zodíaco|signo solar|carta astral|numerologia|numerología)\b/u', $n)) return true;
    /* Alimentos / nutrición */
    if (preg_match('/\b(alimento|alimentos|comida|receta|recetas|nutricion|nutrición|dieta|calorias|calorías|proteina|proteína|vegano|vegetariano|gluten|ayuno)\b/u', $n)) return true;
    /* Medicina / salud (no Ayurveda del glosario local) */
    if (preg_match('/\b(medicina|medico|médico|salud|enfermedad|sintoma|síntoma|tratamiento|remedio|farmaco|fármaco|vacuna|hospital|diagnostico|diagnóstico)\b/u', $n)) return true;
    /* Economía / empresas / productos */
    if (preg_match('/\b(empresa|compania|compañia|precio|comprar|tienda|producto|oferta|mercado|bolsa|bitcoin|crypto)\b/u', $n)) return true;
    return false;
}

/** Alias corto usado en el mensaje final Yahoo */
function es_consulta_actualidad(string $n): bool {
    return es_consulta_no_wiki_primero($n);
}

function buscar_externo(string $query): ?array {
    $n = norm($query);
    $f = DIR_CACHE . '/ext_' . md5($n) . '.json';
    if (file_exists($f) && (time() - (int)@filemtime($f)) < 86400 * 2) {
        $c = json_decode((string)@file_get_contents($f), true);
        if (is_array($c)) return $c;
    }
    $GLOBALS['red_fallo'] = false;

    /*
     * ORQUESTADOR GRATUITO (de arriba hacia abajo):
     *  1) SearXNG   (opcional; si falla se salta)
     *  2) DuckDuckGo Instant
     *  3) Wikipedia (último; omitida en actualidad/ciencia/software/medicina/alimentos/astrología)
     *  Si todo falla → el núcleo ofrece botón Yahoo
     */
    $out = buscar_searxng($query);

    if (!$out) {
        $out = buscar_duckduckgo($query);
        /* Descartar si DDG apunta a Wikipedia irrelevante en temas “no wiki primero” */
        if ($out && es_consulta_no_wiki_primero($n)
            && preg_match('/\b(wikipedia|encyclop|enciclop)\b/ui', (string)($out['url'] ?? '') . ' ' . (string)($out['texto'] ?? ''))) {
            $out = null;
        }
    }

    if (!$out && !es_consulta_no_wiki_primero($n)) {
        $GLOBALS['red_fallo'] = false;
        $out = buscar_wikipedia($query);
    }

    if (!$out) return null;

    @file_put_contents($f, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

function merece_externa(string $n): bool {
    if (preg_match('/\b(busca|buscar|buscame|búscame|internet|enlaces sobre|fuentes|wikipedia|paginas sobre|páginas sobre|pagina de|página de)\b/u', $n)) return true;
    if (preg_match('/\b(pagina oficial|página oficial|sitio oficial|web oficial|direccion oficial|dirección oficial|url oficial|enlace oficial)\b/u', $n)) return true;
    if (preg_match('/\b(lista|restaurante|restaurantes|hotel|hoteles|noticia|noticias|actualidad|linux|software|programacion|programación|codigo|código|empresa|compania|compañia)\b/u', $n)) return true;
    if (preg_match('/\b(muy linux|muylinux|ubuntu|debian|windows|android|iphone)\b/u', $n)) return true;
    if (preg_match('/\b(cuantica|cuántica|quantum|fisica cuantica|física cuántica)\b/u', $n)) return true;
    if (preg_match('/\b(ayurveda|ayurvedica|ayurvédica|medicina ayurvedica|medicina ayurvédica)\b/u', $n)) return true;
    if (preg_match('/\b(arquitectura|arte|pintura|escultura|cultura|culturas|civilizacion|civilización)\b/u', $n)) return true;
    if (preg_match('/\b(vegetariano|vegetariana|vegano|vegana|ovo.?lacto|sin gluten|sin gloten|gluten|alimentacion|alimentación)\b/u', $n)) return true;
    if (preg_match('/\b(biografia|biografía|biografias|biografías)\b/u', $n)) return true;
    if (mb_strlen($n, 'UTF-8') < 12) return false;
    return (bool)preg_match('/\b(que|quién|quien|como|cómo|donde|dónde|cuando|cuándo|cuanto|cuánto|por que|por qué|significa|significado|historia|informacion|información|dime|cuentame|cuéntame|háblame|hablame|quiero|necesito|dame)\b/u', $n);
}

function es_consulta_mundana(string $n): bool {
    if (preg_match('/\b(restaurante|restaurantes|vegana|vegano|vegetariano|vegetariana|ovo.?lacto|sin gluten|sin gloten|gluten)\b/u', $n)) return true;
    if (preg_match('/\b(linux|muylinux|muy linux|ubuntu|windows|android|iphone|codigo|código|programacion|programación|software)\b/u', $n)) return true;
    if (preg_match('/\b(cuantica|cuántica|quantum|arquitectura)\b/u', $n)) return true;
    /* Ayurveda solo mundana si no es consulta del glosario local de Vimana */
    if (preg_match('/\b(ayurveda|ayurvedica|ayurvédica)\b/u', $n) && preg_match('/\b(clinica|clínica|medico|médico|hospital|tratamiento moderno)\b/u', $n)) return true;
    if (preg_match('/\b(noticia|noticias|clima|deportes|futbol|fútbol|politica|política|eleccion|elección|empresa|compania|compañia)\b/u', $n)) return true;
    if (preg_match('/\b(cartelera|horario|horarios|fixture|marcador|resultados|partido|partidos|jornada|nba|mlb|nfl|esta semana|en vivo)\b/u', $n)) return true;
    if (preg_match('/\b(ciencia|fisica|física|quimica|química|biologia|biología|astronomia|astronomía|nasa)\b/u', $n)) return true;
    if (preg_match('/\b(astrologia|astrología|horoscopo|horóscopo|zodiaco|zodíaco|carta astral)\b/u', $n)) return true;
    if (preg_match('/\b(alimento|alimentos|comida|receta|recetas|nutricion|nutrición|dieta|medicina|medico|médico|salud|enfermedad|sintoma|síntoma|vacuna)\b/u', $n)) return true;
    if (preg_match('/\b(pagina oficial|página oficial|sitio oficial|web oficial|direccion oficial|dirección oficial)\b/u', $n)) return true;
    if (preg_match('/\b(arte|pintura|escultura|cultura|culturas|civilizacion|civilización)\b/u', $n) && strpos($n, 'vimana') === false) return true;
    if (preg_match('/\b(biografia|biografía)\b/u', $n) && !preg_match('/\b(sathya sai|sathia sai|sathi sai|satya sai|yogananda|sadhguru|ramana|prabhupada|osho|ramakrishna|ravi shankar)\b/u', $n)) return true;
    return false;
}

/* ----------------------------------------------------------------------------
   7. INTENCIONES
   ---------------------------------------------------------------------------- */

/**
 * Calculadora local segura: solo números, + - * / % ^ ( ), sqrt, pi, e.
 * Sin eval de código PHP arbitrario.
 */
function math_extraer_expresion(string $q): ?string {
    $t = trim($q);
    /* "cuanto es 2+2", "calcula 15*3", "math: 2^8", expresión suelta */
    if (preg_match('/(?:cuanto\s+es|cuánto\s+es|calcula|calcular|resuelve|resultado\s+de|math\s*:?|matematica\s*:?|matemática\s*:?)\s*(.+)$/ui', $t, $m)) {
        $t = trim($m[1]);
    }
    $t = str_replace(['×', '÷', '−', ',', ' '], ['*', '/', '-', '.', ''], $t);
    $t = preg_replace('/\^/', '**', $t) ?? $t;
    /* Solo caracteres seguros */
    if (!preg_match('/^[0-9+\-*\/().%eE\s]+$/', str_replace(['sqrt', 'pi', 'PI'], ['', '', ''], $t))
        && !preg_match('/^(?:sqrt\s*\(|pi|e|[0-9+\-*\/().%\s])+$/i', $t)) {
        /* permitir sqrt( y pi */
        $tmp = preg_replace('/\bsqrt\s*\(/i', '(', $t);
        $tmp = preg_replace('/\bpi\b/i', '3', $tmp ?? '');
        $tmp = preg_replace('/\be\b/i', '2', $tmp ?? '');
        if ($tmp === null || !preg_match('/^[0-9+\-*\/().%\s]+$/', $tmp)) {
            return null;
        }
    }
    if (!preg_match('/\d/', $t)) return null;
    return $t;
}

function math_evaluar(string $expr): ?float {
    $expr = trim($expr);
    $expr = preg_replace('/\s+/', '', $expr) ?? $expr;
    $expr = preg_replace('/\bpi\b/i', '3.141592653589793', $expr) ?? $expr;
    $expr = preg_replace('/\be\b(?![0-9])/i', '2.718281828459045', $expr) ?? $expr;
    /* sqrt(n) → (n)**0.5 de forma simple una sola vez anidada */
    $guard = 0;
    while ($guard++ < 8 && preg_match('/sqrt\(([^()]+)\)/i', $expr, $m)) {
        $inner = math_evaluar($m[1]);
        if ($inner === null || $inner < 0) return null;
        $expr = str_replace($m[0], (string)sqrt($inner), $expr);
    }
    if (!preg_match('/^[0-9+\-*\/().%eE]+$/', $expr)) return null;
    /* ** potencia → usar ** de PHP en eval restringido solo numérico */
    $expr = preg_replace('/[^0-9+\-*\/().%eE]/', '', $expr) ?? '';
    if ($expr === '' || !preg_match('/\d/', $expr)) return null;
    try {
        /* phpcs:ignore — expresión ya sanitizada a dígitos y operadores */
        $r = @eval('return (float)(' . $expr . ');');
        if (!is_numeric($r) || is_nan((float)$r) || is_infinite((float)$r)) return null;
        return (float)$r;
    } catch (Throwable $e) {
        return null;
    }
}

function int_math(string $q, string $n): ?array {
    $expr = math_extraer_expresion($q);
    if ($expr === null) {
        /* expresión casi pura: "2+2", "(15*3)/2" */
        $cand = trim(str_replace(['×', '÷', '−', ' '], ['*', '/', '-', ''], $q));
        $cand = preg_replace('/\^/', '**', $cand) ?? $cand;
        if (preg_match('/^[\d+\-*\/().%\s^]+$/', str_replace('**', '', $cand)) && preg_match('/\d/', $cand)) {
            $expr = $cand;
        } else {
            return null;
        }
    }
    $val = math_evaluar($expr);
    if ($val === null) {
        return respuesta(
            "No pude resolver esa expresión. Prueba con operaciones básicas, por ejemplo:\n• `15 * 3 + 2`\n• `sqrt(144)`\n• `(100 - 25) / 5`\n• `2^10`",
            IMG['pensando']
        );
    }
    $mostrar = rtrim(rtrim(number_format($val, 10, '.', ''), '0'), '.');
    if ($mostrar === '' || $mostrar === '-') $mostrar = '0';
    return respuesta(
        "🧮 **Resultado**\n\n`{$expr}` = **{$mostrar}**\n\nPuedo sumar, restar, multiplicar, dividir, potencias (`2^8`), porcentaje y `sqrt()`.",
        IMG['atento']
    );
}

function int_saludos(string $q, string $n): ?array {
    if (preg_match('/^(hola|holaa+|buenas|buenos dias|buen dia|buenas tardes|buenas noches|namaste|namasté|saludos|hi|hello|hey)\b/u', $n)) {
        return respuesta(
            "Namaste 🌺 Soy Apsara Vimana, anfitriona de Vimana. Estoy aquí para conversar contigo sobre música, arte, sabiduría y los contenidos del proyecto Vimana.\n\nPuedes preguntarme, por ejemplo: qué significa un término sánscrito, quiénes son los maestros espirituales, qué álbumes tiene Vimana Music, o pedirme libros y materiales de la biblioteca.",
            IMG['base']
        );
    }
    if (preg_match('/\b(gracias|te lo agradezco|muy amable|genial|excelente|perfecto)\b/u', $n) && mb_strlen($n, 'UTF-8') < 40) {
        return respuesta("Es un placer acompañarte 🌺. Estoy aquí para lo que necesites.", IMG['gratitud']);
    }
    if (preg_match('/\b(adios|adiós|chao|hasta luego|nos vemos|bye)\b/u', $n) && mb_strlen($n, 'UTF-8') < 40) {
        return respuesta("Que la paz te acompañe 🙏. Vuelve cuando lo desees; aquí estaré.", IMG['gratitud']);
    }
    return null;
}

function int_identidad(string $q, string $n): ?array {
    if (preg_match('/\b(quien eres|que eres|como te llamas|tu nombre|presentate|preséntate|quien es apsara vimana)\b/u', $n)) {
        return respuesta(
            "Soy Apsara Vimana, la asistente de conversación del proyecto Vimana — música, arte y sabiduría.\n\nMi conocimiento vive en los glosarios y la biblioteca de Vimana, y cuando algo se sale de ese universo, puedo buscarlo en fuentes externas por ti.\n\n¿Sobre qué te gustaría conversar?",
            IMG['atento']
        );
    }
    if (preg_match('/\b(que es una apsara|que son las apsaras|apsaras)\b/u', $n)) {
        $g = cargar_glosario();
        [$t] = buscar_en($g, 'apsara', 60);
        $def = $t ? $g[$t] : 'En las tradiciones de la India, las apsaras son seres celestiales de la mitología védica, asociadas al arte, la danza y la belleza.';
        return respuesta("En la tradición de la India, la palabra se refiere a un concepto distinto de mí:\n\n" . $def . "\n\nPor eso me llamo así: como anfitriona de Vimana, evoco esa misma gracia y fluidez 🌺.", IMG['base']);
    }
    if (preg_match('/\b(que puedes hacer|en que puedes ayudar|ayuda|funciones|capacidades|como funcionas)\b/u', $n)) {
        return respuesta(
            "Esto es lo que puedo hacer por ti:\n\n• Sabiduría y términos sánscritos, Vedanta, yoga y tradiciones dhármicas, desde el glosario maestro.\n• Valores Humanos y Educare, según las enseñanzas de Sri Sathya Sai Baba.\n• Maestros espirituales: quiénes son, su mensaje y sus sitios oficiales.\n• Vimana Music: álbumes, temas y dónde escucharlos.\n• Recomendaciones musicales según tu necesidad: estudio, meditación, relajación, sueño.\n• Biblioteca: libros, discursos, cartas, bhajans y materiales de lectura.\n• Búsqueda externa cuando la información no está en Vimana.\n\n¿Por dónde comenzamos?",
            IMG['atento']
        );
    }
    return null;
}

function int_valores(string $q, string $n): ?array {
    if (preg_match('/\bprema\s+sai\b/u', $n) || preg_match('/\bsai\s+baba\b/u', $n)) return null;
    if (preg_match('/\b(quien|quién)\s+(es|era|fue)\s+prema\b/u', $n)) return null;
    if (preg_match('/\bprema\b/u', $n) && preg_match('/\b(quien|quién)\b/u', $n) && !preg_match('/\b(valor|valores|significa|significado|que es|qué es)\b/u', $n)) return null;
    if (preg_match('/\b(sathya|sathia|sathi|satya)\s+sai\b/u', $n)) return null;
    if (preg_match('/\b(quien|quién)\s+(es|era|fue)\s+(sathya|sathia|sathi|satya)\b/u', $n)) return null;

    if (!preg_match('/\b(valores humanos|valor humano|sathya|satya|dharma|shanti|prema|ahimsa|educare)\b/u', $n)) return null;

    $pedir_libros = preg_match('/\b(libro|libros|leer|lectura|descarga|pdf|material|documento)\b/u', $n);

    foreach (VALORES as $v) {
        if (preg_match('/\b' . $v[0] . '\b/u', $n) && !preg_match('/\bvalores humanos\b/u', $n)) {
            if ($v[0] === 'prema' && preg_match('/\bsai\b/u', $n)) continue;
            $txt = "El valor {$v[1]}.\n\n{$v[2]}\n\nAlgunas de sus cualidades:\n• " . implode("\n• ", array_slice($v[3], 0, 10)) . "\n\n";
            return respuesta($txt, IMG['base'], [], ['Libros sobre Valores Humanos', '¿Qué son los Valores Humanos?']);
        }
    }

    if ($pedir_libros) return int_biblioteca($q, 'libros valores humanos educare') ?? null;

    $txt = "Los cinco Valores Humanos fundamentales, según la enseñanza de Sri Sathya Sai Baba, son:\n\n";
    foreach (VALORES as $i => $v) $txt .= ($i + 1) . ". {$v[1]} — {$v[2]}\n\n";
    $txt .= "Ahimsa es considerada la suma y máxima expresión de todos ellos.\n\nSi deseas profundizar, puedo mostrarte las cualidades de un valor en particular o libros y materiales sobre Valores Humanos y Educare.";
    return respuesta($txt, IMG['base'], [], ['Libros sobre Valores Humanos', '¿Qué es Educare?']);
}

function int_sai_baba(string $q, string $n): ?array {
    /* Reescrituras de "quién es X" → forma canónica (incluye sathi) */
    if (preg_match('/\b(quien|quién)\s+(es|era|fue)\s+prema\b/u', $n) && !preg_match('/\b(valor|amor)\b/u', $n)) {
        $n = 'prema sai baba';
    }
    if (preg_match('/\b(quien|quién)\s+(es|era|fue)\s+(sathya|sathia|sathi|satya)\b/u', $n)
        && !preg_match('/\b(valor|verdad)\b/u', $n)) {
        $n = 'sathya sai baba';
    }

    $es_prema_persona = (bool)preg_match('/\bprema\s+sai\b/u', $n)
        || (preg_match('/\bprema\b/u', $n) && preg_match('/\b(quien|quién)\b/u', $n) && !preg_match('/\b(valor|que es|qué es|significa)\b/u', $n));

    /* Todas las grafías: sathya / sathia / sathi / satya + sai [baba] */
    $es_sathya_persona = (bool)preg_match('/\b(sathya|sathia|sathi|satya)\s+sai(?:\s+baba)?\b/u', $n)
        || (bool)preg_match('/\b(sathya|sathia|sathi|satya)\s+sai\s+baba\b/u', $n);

    if (!preg_match('/\b(sai baba|saibaba)\b/u', $n) && !$es_prema_persona && !$es_sathya_persona) return null;

    if (preg_match('/\bshirdi\b/u', $n)) {
        $g = cargar_glosario();
        [$t, $p] = buscar_en($g, 'shirdi sai baba', 40);
        $def = ($t && $p) ? $g[$t] : 'Shirdi Sai Baba fue un maestro del siglo XIX que vivió en Shirdi (Maharashtra, India). Es venerado por hindúes y musulmanes por igual; su enseñanza central era la fe, la paciencia y la compasión, resumida en "Sabiduría (Shraddha) y Paciencia (Saburi)".';
        return respuesta("**Shirdi Sai Baba**\n\n" . limpiar_def($def) . "\n\nEs una figura distinta de Sri Sathya Sai Baba y de Prema Sai Baba.", IMG['base']);
    }
    if (preg_match('/\bprema sai\b/u', $n) || $es_prema_persona) {
        $g = cargar_glosario();
        [$t, $p] = buscar_en($g, 'prema sai baba', 40);
        $def = ($t && $p) ? limpiar_def($g[$t]) : 'Prema Sai Baba (también llamada Prema Sai) es, según la tradición de Sri Sathya Sai Baba, la siguiente encarnación anunciada por él, que vendría después de su partida física.';
        return respuesta(
            "**Prema Sai Baba**\n\n" . $def . "\n\n"
            . "Es una figura distinta de:\n"
            . "• Sri Sathya Sai Baba (1926–2011)\n"
            . "• Shirdi Sai Baba (santo de Shirdi)\n\n"
            . "Si preguntabas por el *valor* Prema (Amor), dímelo y te lo explico.",
            IMG['base'],
            [],
            ['Qué es el valor Prema', 'Sathya Sai Baba', 'Shirdi Sai Baba']
        );
    }

    /* Sathya / Sathia / Sathi / Satya Sai Baba → PERSONAS + respaldo exclusivo del glosario (sección sánscrita del TXT) */
    if ($es_sathya_persona || preg_match('/\b(sathya|sathia|sathi|satya)\b/u', $n)) {
        $quiere_libros = (bool)preg_match('/\b(libro|libros|leer|escrito|obra|vahini|discurso|carta)\b/u', $n);
        /* Respaldo exclusivo: si por cualquier motivo PERSONAS no responde, sacar definición del TXT */
        $g = cargar_glosario();
        $def_txt = '';
        foreach (['sathya sai baba', 'sathia sai baba', 'satya sai baba', 'sathi sai baba'] as $clave) {
            [$t, $p] = buscar_en($g, $clave, 35);
            if ($t && $p) {
                $def_txt = limpiar_def($g[$t]);
                if ($def_txt !== '') break;
            }
        }
        if ($quiere_libros) {
            $r = int_biblioteca('', 'sathya sai baba');
            if ($r) {
                $r['reply'] = "Sobre Sri Sathya Sai Baba, su enseñanza fue: \"Ama a todos, sirve a todos\". Estos son materiales de la biblioteca relacionados:\n\n" . $r['reply'];
                return $r;
            }
        }
        $p = PERSONAS['sathya_sai_baba'];
        $texto = $p['quien'];
        if ($def_txt !== '' && mb_strlen($def_txt, 'UTF-8') > 40) {
            /* Preferir definición del glosario (está en la sección de términos sánscritos del TXT) cuando es más completa */
            $texto = "**Sri Sathya Sai Baba**\n\n" . $def_txt;
        }
        return respuesta(
            $texto . "\n\nPuedo mostrarte más: sus enseñanzas y Valores Humanos, libros y materiales de la biblioteca, o su imagen.",
            IMG['base'],
            array_map(fn($s) => ['t' => $s[0], 'u' => $s[1]], $p['sitios']),
            ['Libros de Sathya Sai Baba', 'Valores Humanos', 'Muéstrame su imagen']
        );
    }
    if (preg_match('/\b(libro|libros|leer|bhajan|discurso|carta|biblioteca)\b/u', $n)) {
        return respuesta_maestro(PERSONAS['sathya_sai_baba'], true);
    }
    return respuesta(
        "Con el nombre Sai Baba se conocen tres figuras distintas. ¿Sobre cuál deseas saber?\n\n• Sri Sathya Sai Baba (1926–2011): el maestro de Puttaparthi, difusor de los Valores Humanos.\n• Shirdi Sai Baba (fallecido en 1918): el santo de Shirdi, maestro de fe y compasión.\n• Prema Sai Baba: la encarnación anunciada por Sri Sathya Sai Baba.",
        IMG['pensando'],
        [],
        ['Sathya Sai Baba', 'Shirdi Sai Baba', 'Prema Sai Baba']
    );
}


function respuesta_maestro(array $p, bool $quiere_libros): array {
    if ($quiere_libros) {
        $r = int_biblioteca('', 'sathya sai baba');
        if ($r) {
            $r['reply'] = "Sobre Sri Sathya Sai Baba, su enseñanza fue: \"Ama a todos, sirve a todos\". Estos son materiales de la biblioteca relacionados:\n\n" . $r['reply'];
            return $r;
        }
    }
    return respuesta(
        $p['quien'] . "\n\nPuedo mostrarte más: sus enseñanzas y Valores Humanos, libros y materiales de la biblioteca, o su imagen.",
        IMG['base'],
        array_map(fn($s) => ['t' => $s[0], 'u' => $s[1]], $p['sitios']),
        ['Libros de Sathya Sai Baba', 'Valores Humanos', 'Muéstrame su imagen']
    );
}

function int_maestros(string $q, string $n): ?array {
    $key = match_persona($n);
    if ($key === null) return null;
    if ($key === 'sathya_sai_baba' && (preg_match('/\bshirdi\b/u', $n) || preg_match('/\bprema\b/u', $n))) {
        return null; // lo maneja int_sai_baba
    }
    $p = PERSONAS[$key];
    $quiere_img    = (bool)preg_match('/\b(imagen|foto|fotografia|retrato|muestrame|muéstrame)\b/u', $n);
    $quiere_libros = (bool)preg_match('/\b(libro|libros|leer|escrito|obra)\b/u', $n);
    if ($quiere_libros) {
        $r = int_biblioteca('', $key === 'sathya_sai_baba' ? 'sathya sai baba' : $p['nombres'][0]);
        if ($r) {
            $r['image'] = IMG['base'];
            $r['reply'] = $p['quien'] . "\n\nMateriales relacionados en la biblioteca:\n\n" . $r['reply'];
            return $r;
        }
    }
    $img = $quiere_img ? url_img($p['imagen']) : IMG['base'];
    $txt = $p['quien'];
    if ($quiere_img) $txt = "Con gusto. Esta es la imagen de {$p['nombres'][0]}.\n\n" . $txt;
    $opciones = ['Otros maestros', 'Sathya Sai Baba'];
    if ($key !== 'sathya_sai_baba') {
        $opciones = ['Otros maestros', 'Libros de Sathya Sai Baba'];
    }
    return respuesta(
        $txt . "\n\n¿Deseas conocer más sobre este maestro, ver otros maestros de la base de Vimana, o sus sitios oficiales?",
        $img,
        array_map(fn($s) => ['t' => $s[0], 'u' => $s[1]], $p['sitios']),
        $opciones
    );
}

/** Lista local de maestros (PERSONAS) — no Wikipedia de “maestro” musical */
function int_lista_maestros(string $q, string $n): ?array {
    $txt = "En la base de conocimiento de Vimana tienes estos maestros espirituales:\n\n";
    $ops = [];
    foreach (PERSONAS as $key => $p) {
        $nombre = $p['nombres'][0];
        $txt .= "• **" . ucwords($nombre) . "**\n";
        $ops[] = 'Quién es ' . $nombre;
    }
    $txt .= "\nPregúntame por cualquiera: por ejemplo «quién es Sadhguru» o «quién es Ramana Maharshi».";
    return respuesta($txt, IMG['base'], [], array_slice($ops, 0, 6));
}

function int_vimana(string $q, string $n): ?array {
    if (strpos($n, 'vimana') === false) return null;

    /* Manejo unificado de Vimana Music / Vimana Música (Español e Inglés) */
    if (preg_match('/\b(vimana\s+music|vimana\s+musica|what\s+is\s+vimana\s+music|que\s+es\s+vimana\s+musica)\b/u', $n)) {
        $txt = "Vimana Music es el proyecto musical **del propio sitio** vimana.rf.gd.\n\n"
            . "Incluye:\n"
            . "• **SO HAM** — música moderna **devocional hindu-latina** (cantada); no es clásica india ni “para meditar”\n"
            . "• **Ananda** (Sat Chit Ananda) y **Divinaversum**\n"
            . "• **Podcast Vimana** y **Sadhana**\n"
            . "• **Música relajante** para descanso\n\n"
            . "Todo se escucha en Vimana. Los géneros externos (india clásica, jazz, etc.) se recomiendan con enlaces a YouTube.";
        $links = [];
        foreach (ALBUMES as $a) {
            $links[] = ['t' => $a['titulo'], 'u' => $a['url']];
        }
        return respuesta($txt, IMG['base'], $links, ['SO HAM', 'Ananda', 'Divinaversum', 'Podcast Vimana']);
    }

    if (preg_match('/\b(redes|whatsapp|facebook|tiktok|instagram|telegram|canal)\b/u', $n)) {
        return respuesta(
            "Estos son los canales oficiales de Vimana:\n\n" . implode("\n", array_map(fn($r) => "• {$r[0]}", REDES)),
            IMG['promover'],
            array_map(fn($r) => ['t' => $r[0], 'u' => $r[1]], REDES)
        );
    }
    if (preg_match('/\b(sitio|web|pagina|página|url|enlace)\b/u', $n) && !preg_match('/\b(musica|música)\b/u', $n)) {
        return respuesta("El sitio web de Vimana es:\n\n" . SITE_URL . "\n\nEl agente vive dentro de él, en /ia/.", IMG['base'],
            [['t' => 'Sitio web de Vimana', 'u' => SITE_URL]]);
    }
    if (preg_match('/\b(proyecto|acerca de)\b/u', $n) && !preg_match('/\b(musica|música|significa|palabra|sanscrito|sánscrito)\b/u', $n)) {
        return respuesta(
            "Vimana es un espacio de Música, Arte y Sabiduría. Su identidad: unidad en la diversidad, sabiduría sin imposiciones.\n\nEs un lugar para compartir sabiduría, música, arte, conocimiento, inspiración y práctica interior. Yo, Apsara Vimana, soy su anfitriona.\n\n¿Te gustaría conocer Vimana Music o la biblioteca?",
            IMG['base'],
            [['t' => 'Sitio web de Vimana', 'u' => SITE_URL]],
            ['¿Qué álbumes tiene Vimana Music?', '¿Qué significa la palabra Vimana?', 'Biblioteca']
        );
    }
    if (preg_match('/\b(significa|significado|palabra|sanscrito|sánscrito|que significa|qué significa)\b/u', $n)
        && !preg_match('/\b(musica|música|cancion|álbum|album)\b/u', $n)) {
        return respuesta(
            "«Vimana» (विमान) es una palabra de origen sánscrito que en las escrituras védicas se refiere a vehículos voladores o palacios celestiales. En un contexto espiritual y filosófico, representa la elevación de la conciencia y la trascendencia de los límites terrenales.",
            IMG['base'],
            [], // SIN enlaces distractores a la web de Vimana
            ['¿Qué es Vimana Music?', 'Glosario sánscrito']
        );
    }
    return null;
}

/**
 * Carga la base de recomendaciones musicales del TXT (secciones VII y VIII).
 * Una sola pasada; se cachea en cache/musica_index.json
 */
function cargar_musica(): array {
    $cache = DIR_CACHE . '/musica_index.json';
    $mt = @filemtime(F_GLOSARIO);
    if ($mt && file_exists($cache)) {
        $j = json_decode((string)@file_get_contents($cache), true);
        if (is_array($j) && ($j['mt'] ?? 0) === $mt && !empty($j['idx'])) {
            return $j['idx'];
        }
    }
    $idx = [];
    if (!file_exists(F_GLOSARIO)) return [];
    $lineas = file(F_GLOSARIO, FILE_IGNORE_NEW_LINES);
    if (!$lineas) return [];

    /* Filas de ejemplo / instrucciones internas — NUNCA mostrar al usuario */
    $basura_titulo = [
        'tipo', 'artista', 'artista / contenido', 'necesidad del usuario',
        'género / estilo', 'genero / estilo', 'formato', 'nombre', 'enlace',
        'descripcion', 'descripción', 'palabras clave', 'ejemplos para recomendar',
    ];

    $en_musica = false;
    foreach ($lineas as $l) {
        $ls = trim($l);
        if ($ls === '') continue;
        $up = mb_strtoupper($ls, 'UTF-8');
        if (strpos($up, 'RECOMENDACIONES DE MÚSICA') !== false
            || strpos($up, 'RECOMENDACIONES DE MUSICA') !== false
            || strpos($up, 'RECOMENDACIONES MUSICALES') !== false) {
            $en_musica = true;
            continue;
        }
        if ($en_musica && (strpos($up, 'FIN DE LA BASE DE RECOMENDACIONES') !== false
            || strpos($up, 'FIN DE RECOMENDACIONES MUSICALES') !== false)) {
            $en_musica = false;
            continue;
        }
        if (!$en_musica || strpos($ls, '|') === false) continue;

        $parts = array_map('trim', explode('|', $ls));
        if (count($parts) < 4) continue;
        $titulo = $parts[0];
        $tn = mb_strtolower($titulo, 'UTF-8');

        /* Saltar cabeceras, ejemplos "Quiero…", plantillas USUARIO, reglas del agente */
        if (in_array($tn, $basura_titulo, true)) continue;
        if (preg_match('/^quiero\b/ui', $titulo)) continue;
        if (preg_match('/^usuario\b/ui', $titulo)) continue;
        if (preg_match('/^(si |cuando |el engine|apsara debe|no debe|debe |paso \d|1\.|2\.|3\.|4\.|5\.|6\.|7\.|8\.|9\.|10\.)/ui', $titulo)) continue;
        if (preg_match('/^(pregunta|respuesta|enlace|nota|importante|formato|proposito|propósito|reglas)/ui', $titulo)) continue;
        if (mb_strlen($titulo, 'UTF-8') < 2) continue;

        /* URL real (preferir youtube / playlist / vimana, no genérico vacío) */
        $url = '';
        foreach ($parts as $p) {
            if (preg_match('#https?://[^\s\|]+#u', $p, $m)) {
                $u = rtrim($m[0], '.;,)');
                /* Descartar solo el dominio genérico sin path útil si hay mejor */
                if ($url === '' || (strpos($u, 'youtube') !== false || strpos($u, 'youtu.be') !== false || strpos($u, 'vimana') !== false || strpos($u, 'playlist') !== false)) {
                    $url = $u;
                }
            }
        }

        /* Clasificar: genero (tipos en mayúsculas cortas) vs artista */
        $es_genero = (bool)preg_match('/^(bhajan|mantra|kirtan|cl[aá]sica|bansuri|sitar|santoor|fusi[oó]n|ambiental|bollywood|m[uú]sica de templo|new age|gregoriano|zen|cuencos)/ui', $titulo);

        $genero = $es_genero ? $titulo : (string)($parts[1] ?? '');
        $estilo = (string)($parts[2] ?? $parts[1] ?? '');
        $desc = '';
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            if ($parts[$i] === '' || $parts[$i] === '—') continue;
            if (preg_match('#^https?://#i', $parts[$i])) continue;
            if (mb_strlen($parts[$i], 'UTF-8') >= 12) {
                $desc = $parts[$i];
                break;
            }
        }

        $key = norm($titulo);
        if ($key === '' || mb_strlen($key, 'UTF-8') < 2) continue;

        $idx[$key] = [
            'titulo'  => $titulo,
            'tipo'    => $es_genero ? 'genero' : 'artista',
            'genero'  => $genero,
            'estilo'  => $estilo,
            'desc'    => $desc,
            'url'     => $url,
            'norm'    => norm($titulo . ' ' . $genero . ' ' . $estilo . ' ' . $desc),
            'tiene_url' => $url !== '' && !preg_match('#^https?://(www\.)?youtube\.com/?$#i', $url),
        ];
    }

    if ($mt) {
        @file_put_contents($cache, json_encode(['mt' => $mt, 'idx' => $idx], JSON_UNESCAPED_UNICODE));
    }
    return $idx;
}

/**
 * Busca géneros + artistas con enlace. No devuelve filas "Quiero…" ni instrucciones.
 * $max_generos + $max_artistas para respuesta clara.
 */
function buscar_musica_base(string $n, int $max = 6): array {
    $idx = cargar_musica();
    if (!$idx) return [];

    $tokens = array_values(array_filter(
        explode(' ', $n),
        fn($t) => mb_strlen($t, 'UTF-8') >= 3
            && !in_array($t, ['quiero', 'necesito', 'busco', 'recomend', 'recomienda', 'recomendame', 'ponme', 'dame', 'para', 'con', 'una', 'unos', 'sobre', 'musica', 'musicas', 'alegre', 'algo'], true)
    ));
    $boost_map = [
        'bhajan' => ['bhajan', 'devocional', 'jalota', 'jagjit', 'anup'],
        'mantra' => ['mantra', 'gayatri', 'japa', 'deva premal', 'snatam'],
        'kirtan' => ['kirtan', 'sankirtana', 'krishna das', 'harinama'],
        'india'  => ['india', 'hindustani', 'carnatic', 'carnatica', 'raga', 'sitar', 'bansuri', 'tabla', 'bhajan', 'mantra'],
        'gregoriano' => ['gregoriano', 'canto gregoriano', 'gregorian'],
        'clasica' => ['clasica', 'clásica', 'hindustani', 'carnatic'],
        'zen' => ['zen', 'cuenco', 'tibetano', 'ambiental'],
        'dormir' => ['dormir', 'sueno', 'sueño', 'insomnio', 'liquid mind', 'naturaleza'],
        'meditar' => ['meditar', 'meditacion', 'bansuri', 'mantra', 'ambiental'],
        'yoga' => ['yoga', 'mantra', 'bansuri'],
        'new age' => ['new age', 'enya', 'kitaro', 'yanni', 'vangelis'],
        'bollywood' => ['bollywood'],
        'templo' => ['templo', 'aarti', 'arti', 'puja', 'bhajan'],
        'estudiar' => ['estudiar', 'estudio', 'instrumental', 'clasica', 'ambient', 'sitar', 'bansuri', 'concentrac'],
        'concentrar' => ['concentrar', 'concentracion', 'focus', 'instrumental', 'ambient'],
        'trabajar' => ['trabajar', 'trabajo', 'ambient', 'jazz', 'new age', 'fusion'],
        'relaj' => ['relajar', 'relajacion', 'naturaleza', 'cuencos', 'zen', 'ambient'],
        'jazz' => ['jazz', 'miles davis', 'coltrane', 'bill evans', 'brubeck'],
        'mozart' => ['mozart', 'bach', 'beethoven', 'chopin', 'vivaldi', 'clasica'],
    ];
    foreach ($boost_map as $trigger => $extra) {
        if (strpos($n, $trigger) !== false) {
            $tokens = array_values(array_unique(array_merge($tokens, $extra)));
        }
    }
    if (!$tokens) $tokens = ['bhajan', 'devocional'];

    $puntos = [];
    foreach ($idx as $key => $row) {
        /* Seguridad: nunca devolver ejemplos «Quiero…» aunque estén en caché vieja */
        if (preg_match('/^quiero\b/ui', (string)($row['titulo'] ?? ''))) continue;
        if (preg_match('/^usuario\b/ui', (string)($row['titulo'] ?? ''))) continue;
        if (strpos((string)($row['titulo'] ?? ''), 'WhatsApp') !== false) continue;
        if (strpos((string)($row['titulo'] ?? ''), 'Facebook') !== false) continue;
        if (strpos((string)($row['titulo'] ?? ''), 'TikTok') !== false) continue;

        $hay = $row['norm'];
        $s = 0;
        foreach ($tokens as $t) {
            if (strpos($hay, $t) !== false) {
                $s += mb_strlen($t, 'UTF-8') >= 5 ? 8 : 4;
            }
        }
        if ($row['tipo'] === 'genero') $s += 3;
        if (!empty($row['tiene_url'])) $s += 6;
        /* Exigir URL útil para artistas externos (no youtube.com pelado) */
        if (($row['tipo'] ?? '') === 'artista' && empty($row['tiene_url'])) {
            $s = (int)max(0, $s - 4);
        }
        if ($s > 0) $puntos[$key] = $s;
    }
    arsort($puntos);

    $generos = [];
    $artistas = [];
    foreach (array_keys($puntos) as $k) {
        $row = $idx[$k];
        if ($row['tipo'] === 'genero' && count($generos) < 3) {
            $generos[] = $row;
        } elseif ($row['tipo'] === 'artista' && count($artistas) < max(3, $max - 2)) {
            $artistas[] = $row;
        }
        if (count($generos) + count($artistas) >= $max) break;
    }
    return array_merge($generos, $artistas);
}

/**
 * Formato limpio para el usuario: géneros + artistas con enlace de video.
 * No muestra filas "Quiero…", ni instrucciones internas.
 */
function formatear_hits_musica(array $hits, array &$links): string {
    $generos = [];
    $artistas = [];
    foreach ($hits as $h) {
        if (($h['tipo'] ?? '') === 'genero') $generos[] = $h;
        else $artistas[] = $h;
    }
    $txt = '';
    if ($generos) {
        $txt .= "**Géneros / estilos**\n";
        foreach ($generos as $h) {
            $txt .= "• **{$h['titulo']}**";
            if (!empty($h['estilo'])) $txt .= " — " . mb_substr($h['estilo'], 0, 80, 'UTF-8');
            elseif (!empty($h['desc'])) $txt .= " — " . mb_substr($h['desc'], 0, 80, 'UTF-8');
            $txt .= "\n";
            if (!empty($h['tiene_url']) && $h['url'] !== '') {
                $links[] = ['t' => 'Escuchar: ' . $h['titulo'], 'u' => $h['url']];
            }
        }
        $txt .= "\n";
    }
    if ($artistas) {
        $txt .= "**Artistas / listas (con enlace cuando hay video)**\n";
        foreach ($artistas as $h) {
            $txt .= "• **{$h['titulo']}**";
            if (!empty($h['genero'])) $txt .= " · " . mb_substr((string)$h['genero'], 0, 40, 'UTF-8');
            $txt .= "\n";
            if (!empty($h['url'])) {
                $links[] = ['t' => $h['titulo'], 'u' => $h['url']];
            }
        }
    }
    return $txt;
}

function int_musica(string $q, string $n): ?array {
    $musica_kw = (bool)preg_match('/\b(musica|musicas|cancion|canciones|album|albumes|escuchar|escucho|letra|radio|podcast|bhajan|bhajans|mantra|mantras|sonido|sonidos|playlist|reproducir|genero|género|estilo musical)\b/u', $n);
    $genero_kw = (bool)preg_match('/\b(bhajan|kirtan|mantra|raga|bansuri|sitar|santoor|hindustani|carnatic|carnatica|bollywood|gregoriano|new age|zen|cuencos?|tibetano|templo|aarti|india|indien|devocional|instrumental)\b/u', $n);
    $necesidad = (bool)preg_match('/\b(dormir|sueno|sueño|descansar|insomnio|relajar|relajacion|relajarse|meditar|meditacion|estudiar|estudio|concentrar|concentracion|trabajar|yoga|devocion|devocional|sanar|sanacion)\b/u', $n);
    $es_vimana = strpos($n, 'vimana') !== false;

    if (!$musica_kw && !$es_vimana && !$necesidad && !$genero_kw) return null;
    if (!$musica_kw && !$es_vimana && !$genero_kw && $necesidad
        && !preg_match('/\b(quiero|necesito|busco|recomend|ponme|dame|para)\b/u', $n)) {
        return null;
    }

    /* 0a) Catálogo de álbumes Vimana (no confundir con géneros externos) */
    if (preg_match('/\b(albumes?|álbumes?|discos?|catalogo|catálogo)\b/u', $n)
        && (strpos($n, 'vimana') !== false || preg_match('/\b(tienes|tiene|lista|muestra|mostrar|cuales|cuáles)\b/u', $n)
            || !$genero_kw)) {
        if (strpos($n, 'vimana') !== false || preg_match('/\b(albumes?|álbumes?)\b/u', $n) && !preg_match('/\b(india|jazz|gregoriano|bhajan|mantra|bollywood)\b/u', $n)) {
            $txt = "**Álbumes y recursos de Vimana** (se escuchan en el sitio vimana.rf.gd):\n\n";
            $links = [];
            foreach (ALBUMES as $key => $a) {
                $txt .= "• **{$a['titulo']}**\n";
                if ($key === 'soham') {
                    $txt .= "  Moderna devocional hindu-latina (cantada). No es clásica india ni “para meditar”.\n";
                } elseif (!empty($a['estilo'])) {
                    $txt .= "  " . $a['estilo'] . "\n";
                }
                $links[] = ['t' => $a['titulo'], 'u' => $a['url']];
            }
            $txt .= "\nLos **géneros** (india, jazz, gregoriano…) no son álbumes de Vimana: si los pides, te doy enlaces externos a YouTube para escuchar fuera del sitio.";
            return respuesta($txt, IMG['base'], $links, ['SO HAM', 'Ananda', 'Divinaversum', 'Música india']);
        }
    }

    /* 0b) Música india → géneros + enlaces YouTube (NO es catálogo Vimana) */
    if (preg_match('/\b(musica india|música india|india|hindustani|carnatic|carnatica)\b/u', $n)
        && !preg_match('/\b(soham|so ham|ananda|divinaversum)\b/u', $n)) {
        $txt = "**Música india** (esto **no** son álbumes de Vimana; enlaces para escuchar en YouTube):\n\n";
        $txt .= "**Géneros**\n";
        $txt .= "• Bhajan — canto devocional\n";
        $txt .= "• Mantra / Kirtan\n";
        $txt .= "• Clásica hindustaní y carnática\n";
        $txt .= "• Bansuri, Sitar, Santoor, Sarod\n";
        $txt .= "• Música de templo / Bollywood instrumental\n\n";
        $txt .= "**Escuchar fuera de Vimana**\n";
        $links = [
            ['t' => 'Bhajans devocionales', 'u' => 'https://www.youtube.com/results?search_query=Traditional+Indian+Devotional+Bhajans'],
            ['t' => 'Kirtan (Krishna Das)', 'u' => 'https://www.youtube.com/results?search_query=Krishna+Das+Live+Kirtan'],
            ['t' => 'Ragas hindustaní', 'u' => 'https://www.youtube.com/results?search_query=Hindustani+Classical+Ragas'],
            ['t' => 'Carnática instrumental', 'u' => 'https://www.youtube.com/results?search_query=Carnatic+Classical+Instrumental'],
            ['t' => 'Bansuri (Chaurasia)', 'u' => 'https://www.youtube.com/results?search_query=Hariprasad+Chaurasia+Classical+Flute'],
            ['t' => 'Sitar (Ravi Shankar)', 'u' => 'https://www.youtube.com/results?search_query=Ravi+Shankar+Evening+Raga+Sitar'],
        ];
        $hits = buscar_musica_base('musica india bhajan bansuri sitar', 6);
        foreach ($hits as $h) {
            if (!empty($h['tiene_url']) && $h['url'] !== '') {
                $links[] = ['t' => $h['titulo'], 'u' => $h['url']];
            }
        }
        $txt .= "Abajo tienes botones con búsquedas en YouTube.\n\n";
        $txt .= "En **Vimana** la música propia es otra línea (SO HAM = devocional hindu-latino moderno, Ananda, Divinaversum…), no sustituye a la clásica india.";
        $links[] = ['t' => 'SO HAM en Vimana (hindu-latino)', 'u' => ALBUMES['soham']['url']];
        return respuesta($txt, IMG['base'], $links, ['Bhajans', 'Mantras', 'Álbumes de Vimana', 'Para dormir']);
    }

    /* 1) Álbum / recurso concreto de Vimana (está en el sitio) */
    foreach (ALBUMES as $key => $a) {
        foreach ($a['nombres'] as $nombre) {
            if (strpos($n, norm($nombre)) !== false) {
                $txt = "**{$a['titulo']}**\n";
                if (!empty($a['estilo'])) $txt .= $a['estilo'] . "\n";
                $txt .= "\nTemas / contenido:\n• " . implode("\n• ", array_slice($a['temas'], 0, 12));
                if (count($a['temas']) > 12) $txt .= "\n• …y más.";
                $txt .= "\n\nEscucha en Vimana (no hace falta salir del sitio):";
                $links = [['t' => 'Abrir en Vimana: ' . $a['titulo'], 'u' => $a['url']]];
                $img = $a['imagen'] ? url_img($a['imagen']) : IMG['base'];
                $ops = ['Otros álbumes de Vimana', 'Divinaversum', 'Ananda', 'Podcast Vimana'];
                if ($key === 'soham') {
                    $ops = ['Ananda', 'Divinaversum', 'Bhajans externos', 'Otros álbumes de Vimana'];
                }
                return respuesta($txt, $img, $links, $ops);
            }
        }
    }

    $links = [];
    $txt = '';
    $ops = ['Bhajans', 'Mantras', 'Música india', 'Música para dormir'];

    /* 2) Dormir / relax → Vimana + base */
    if (preg_match('/\b(dormir|sueno|sueño|descansar|insomnio|relajar|relajacion|relajarse|ansiedad|estres)\b/u', $n)) {
        $a = ALBUMES['relax'];
        $txt = "Para dormir o relajarte te recomiendo primero el recurso de **Vimana**:\n\n«{$a['titulo']}»\n(lluvia, cuencos tibetanos, Zen, ambient suave).\n\n";
        $links[] = ['t' => 'Vimana — Música para dormir', 'u' => $a['url']];
        $hits = buscar_musica_base($n . ' dormir zen ambient naturaleza', 4);
        if ($hits) {
            $txt .= "De la base musical del glosario también encajan:\n";
            foreach ($hits as $h) {
                $txt .= "• {$h['titulo']}\n";
                if ($h['url'] !== '') $links[] = ['t' => $h['titulo'], 'u' => $h['url']];
            }
        }
        return respuesta($txt, $a['imagen'] ? url_img($a['imagen']) : IMG['base'], $links, $ops);
    }

    /* 3) Meditar / yoga — SO HAM NO se recomienda aquí */
    if (preg_match('/\b(meditar|meditacion|mindfulness|yoga)\b/u', $n)
        && !preg_match('/\b(bhajan|kirtan|soham|so ham)\b/u', $n)) {
        $txt = "Para meditar o yoga conviene **instrumental / ambient / bansuri** (poca letra).\n\n";
        $links[] = ['t' => 'Música relajante Vimana', 'u' => ALBUMES['relax']['url']];
        $links[] = ['t' => 'Sadhana (en Vimana)', 'u' => ALBUMES['sadhana']['url']];
        $txt .= "En **Vimana** (en el sitio): Música relajante y Sadhana.\n\n";
        $hits = buscar_musica_base($n . ' meditar bansuri ambient', 6);
        $txt .= formatear_hits_musica($hits, $links);
        $txt .= "\nNota: **SO HAM** (Vimana) es música **moderna devocional hindu-latina cantada**, no disco de meditación ni de estilo clásico indio.";
        return respuesta($txt, IMG['base'], $links, $ops);
    }

    /* 4) Catálogo Vimana Music — todo lo que SÍ está en el sitio */
    if ($es_vimana && preg_match('/\b(musica|album|cancion|canciones|que tiene|temas)\b/u', $n)) {
        $txt = "**Vimana Music** (todo esto se escucha en vimana.rf.gd):\n\n";
        foreach (ALBUMES as $key => $a) {
            $txt .= "• **{$a['titulo']}**";
            if ($key === 'soham') {
                $txt .= " — moderna devocional hindu-latina (cantada)";
            } elseif (!empty($a['estilo'])) {
                $txt .= " — " . mb_substr($a['estilo'], 0, 60, 'UTF-8');
            }
            $txt .= "\n";
            $links[] = ['t' => $a['titulo'], 'u' => $a['url']];
        }
        $txt .= "\nSi buscas géneros que **no** están en Vimana (clásica india, jazz, gregoriano…), pide el género y te doy enlaces externos a YouTube.";
        return respuesta($txt, IMG['base'], $links, ['SO HAM', 'Ananda', 'Divinaversum', 'Podcast Vimana']);
    }

    /* 5) Género / artista — respuesta limpia: géneros + artistas con enlace de video */
    $hits = buscar_musica_base($n, 8);
    if ($hits) {
        $txt = "Recomendaciones musicales según lo que pediste:\n\n";
        $txt .= formatear_hits_musica($hits, $links);

        /* Catálogo Vimana (en el sitio) vs enlaces externos YouTube */
        if (preg_match('/\b(bhajan|kirtan|devocion|devocional|canto|cantado)\b/u', $n)) {
            $txt .= "\n**En Vimana (en el sitio):**\n";
            $txt .= "• **SO HAM** — música moderna **devocional hindu-latina** (cantada). No es clásica india ni “música para meditar”.\n";
            $txt .= "• También: Ananda, Divinaversum, Podcast, Sadhana.\n";
            $links[] = ['t' => 'SO HAM en Vimana', 'u' => ALBUMES['soham']['url']];
            $links[] = ['t' => 'Ananda en Vimana', 'u' => ALBUMES['ananda']['url']];
            $links[] = ['t' => 'Divinaversum en Vimana', 'u' => ALBUMES['divinaversum']['url']];
        } elseif (preg_match('/\b(india|hindustani|carnatic|raga|sitar|bansuri)\b/u', $n)) {
            $txt .= "\nLos enlaces de arriba son para **escuchar fuera de Vimana** (YouTube).\n";
            $txt .= "Vimana en su web tiene música propia distinta: SO HAM (devocional hindu-latino moderno), Ananda, Divinaversum, Podcast, Sadhana.\n";
            $links[] = ['t' => 'SO HAM en Vimana', 'u' => ALBUMES['soham']['url']];
            $links[] = ['t' => 'Ananda en Vimana', 'u' => ALBUMES['ananda']['url']];
        }

        $txt .= "\n¿Quieres más bhajan, mantra, instrumental o clásica india?";
        return respuesta($txt, IMG['base'], $links, $ops);
    }

    /* 6) Genérico: menú de géneros + Vimana */
    if ($musica_kw || $genero_kw || $necesidad) {
        foreach (ALBUMES as $a) {
            $links[] = ['t' => $a['titulo'], 'u' => $a['url']];
        }
        return respuesta(
            "Puedo recomendarte desde la base musical del glosario y desde Vimana Music:\n\n"
            . "• **Géneros:** bhajan, mantra, kirtan, clásica hindustaní/carnática, bansuri, sitar, música de templo, bollywood instrumental, new age, zen, gregoriano…\n"
            . "• **Necesidad:** dormir, meditar, yoga, estudiar, devoción\n"
            . "• **Vimana Music:** Ananda, SO HAM, Sadhana, Podcast, Música relajante\n\n"
            . "Dime por ejemplo: «música india para meditar», «bhajans», «mantras», «gregoriano» o «para dormir».",
            IMG['base'],
            $links,
            $ops
        );
    }
    return null;
}

function int_biblioteca(string $q, string $n): ?array {
    $idx = cargar_biblioteca();
    if (!$idx) return null;

    /* Detectar intención de biblioteca / tema Sai (también cuando se llama forzado sin "libro") */
    $biblio_kw = (bool)preg_match('/\b(libro|libros|leer|lectura|descarga|pdf|documento|biblioteca|bhajan|bhajanavali|vahini|vahinis|discurso|discursos|carta|cartas|entrevista|educare|autor|obra|obras|material|materiales|mensajes|chinna|katha|autores?\s+varios)\b/u', $n);
    $tema_sai  = (bool)preg_match('/\b(sathya|sathia|sathi|satya|sai|baba|vahini|bhajan|educare)\b/u', $n);
    $tema_cat  = (bool)preg_match('/\b(bhajans?\s+y\s+cantos|educare|valores humanos|vahinis?|cartas?|discursos?|meditaci[oó]n|yoga|gita|escrituras|autores?\s+varios|entrevistas?)\b/u', $n);

    /* Si viene del cascade sin palabras de biblioteca ni tema, no entrar */
    if (!$biblio_kw && !$tema_sai && !$tema_cat && $n !== '') return null;

    /* Categorías alineadas con biblioteca.html (6 secciones) */
    $filtros = [
        'Libros de Sathya Sai Baba'  => 'bhajanavali|chinna|katha|divinas palabras|mensajes de sathya|lluvias de verano|ramakatha|sathya vahini|jnana vahini|dharma vahini|ideal sai|directivas|conversando|dialogos con sai|enseñanzas|prashanti yoga|vidya|sadhana|prema yoga|dhyana yoga|pensamiento del dia|prasnotara|upanishads|rosas de verano|recopilacion de mensajes',
        'Autores varios'             => 'murphet|kasturi|hislop|parodi|rao|seema|howard|john s|n\. kasturi|amando a dios|buscando la divinidad|camino a la luz|ahi donde termina',
        'Vahinis'                    => 'vahini',
        'Discursos'                  => 'discurso vol|discursos vol|lluvias de verano \(|rosas de verano \(',
        'Discursos especiales'       => 'discurso de meditacion|discursos en africa|discursos sobre bhagavad|discursos completos|discursos del año|entrevista hecha por blitz|saisruthi',
        'Cartas'                     => 'carta|cartas|prema dhaara|swami me escribe|indulal',
    ];

    /* Stop-words + normalizar tokens de búsqueda */
    $stop = ['libro','libros','leer','lectura','quiero','sobre','para','con','una','unos','unas',
             'tiene','tienes','de','del','la','el','los','las','mas','más','area','área',
             'materiales','material','documentos','documento','pdf','descarga','biblioteca',
             'dados','por','escritos','escrita','escritas'];
    $tokens = array_values(array_filter(
        explode(' ', $n),
        fn($t) => mb_strlen($t, 'UTF-8') >= 3 && !in_array($t, $stop, true)
    ));

    /* Sinónimos / boost según consulta y categorías de biblioteca.html */
    $boost = [];
    if (preg_match('/\b(sathya|sathia|sathi|satya|sai|baba)\b/u', $n)
        && !preg_match('/\b(discurso|carta|vahini|autor)\b/u', $n)) {
        /* consulta genérica de libros de Sai → priorizar títulos Drive + vahini */
        $boost = array_merge($boost, ['sathya', 'sai', 'mensaje', 'chinna', 'bhajanavali', 'vahini']);
    }
    if (preg_match('/\bvahini/u', $n))   $boost[] = 'vahini';
    if (preg_match('/\bbhajan/u', $n))   $boost[] = 'bhajan';
    if (preg_match('/\bcarta/u', $n))    $boost = array_merge($boost, ['carta', 'prema', 'dhaara', 'escribe']);
    if (preg_match('/\bdiscurso/u', $n)) $boost = array_merge($boost, ['discurso', 'discursos']);
    if (preg_match('/\bchinna|katha/u', $n)) $boost = array_merge($boost, ['chinna', 'katha']);
    if (preg_match('/\bautores?\s+varios\b/u', $n)) $boost = array_merge($boost, ['murphet', 'kasturi', 'hislop', 'parodi']);
    if (preg_match('/\bentrevista/u', $n)) $boost[] = 'entrevista';
    $tokens = array_values(array_unique(array_merge($tokens, $boost)));

    $puntos = [];
    foreach ($idx as $titulo => $url) {
        $nt = norm($titulo);
        $s  = 0;
        foreach ($tokens as $t) {
            if (strpos($nt, $t) !== false) {
                $s += (in_array($t, ['sathya', 'sai', 'baba', 'vahini', 'discurso'], true) ? 12 : mb_strlen($t, 'UTF-8'));
            }
        }
        if ($s > 0) $puntos[$titulo] = $s;
    }
    arsort($puntos);
    $elegidos = array_slice(array_keys($puntos), 0, 8, true);

    if (!$elegidos) {
        /* Categorías = las 6 secciones de biblioteca.html */
        $conteo = [
            'Libros de Sathya Sai Baba' => 0,
            'Autores varios'            => 0,
            'Vahinis'                   => 0,
            'Discursos'                 => 0,
            'Discursos especiales'      => 0,
            'Cartas'                    => 0,
        ];
        foreach ($idx as $titulo => $url) {
            $nt = norm($titulo);
            $hit = false;
            foreach ($filtros as $cat => $pat) {
                if (preg_match('/(' . $pat . ')/u', $nt)) {
                    $conteo[$cat] = ($conteo[$cat] ?? 0) + 1;
                    $hit = true;
                    break;
                }
            }
            if (!$hit) $conteo['Otros'] = ($conteo['Otros'] ?? 0) + 1;
        }
        $txt = "La biblioteca de Vimana reúne " . count($idx) . " recursos de lectura de Sri Sathya Sai Baba:\n\n";
        foreach ($conteo as $cat => $c) {
            if ($c > 0) $txt .= "• {$cat} ({$c})\n";
        }
        $txt .= "\n¿Qué área deseas explorar? También puedes abrir la biblioteca completa.";
        $opciones = array_values(array_filter(array_keys($conteo), fn($c) => ($conteo[$c] ?? 0) > 0));
        $opciones = array_slice($opciones, 0, 6);
        return respuesta(
            $txt,
            IMG['base'],
            [['t' => 'Abrir biblioteca completa', 'u' => IA_URL . 'biblioteca.html']],
            $opciones
        );
    }

    $txt = "Encontré estos materiales en la biblioteca:\n\n";
    $links = [];
    foreach ($elegidos as $titulo) {
        $txt .= "📖 {$titulo}\n";
        $links[] = ['t' => $titulo, 'u' => $idx[$titulo]];
    }
    $txt .= "\nSi buscas otro título, autor o tema (Vahinis, bhajans, discursos, cartas…), dime con más detalle.";
    return respuesta(
        $txt,
        IMG['base'],
        $links,
        ['Vahinis', 'Bhajans y cantos', 'Mensajes y discursos', 'Abrir biblioteca completa']
    );
}

function int_entidades_clave(string $q, string $n): ?array {
    if (preg_match('/\b(caitanya|chaitanya|caitania|cheitania|caitannia)\b/u', $n)
        && preg_match('/\b(mahaprabhu|mahaprabu|maha\s*prabhu|prabhu)\b/u', $n)) {
        return respuesta(
            "**Sri Caitanya Mahaprabhu** (también Chaitanya Mahāprabhu) es el maestro central de la tradición Gauḍīya Vaiṣṇava.\n\n"
            . "Promovió el saṅkīrtana (canto de los santos nombres) y la devoción a Kṛṣṇa. Su vida se narra en el *Śrī Caitanya-caritāmṛta*.\n\n"
            . "No debe confundirse con el término sánscrito *caitanya* (conciencia) ni con el maha-mantra Hare Krishna.",
            IMG['base']
        );
    }
    if (preg_match('/\b(caitanya|chaitanya|caitania)\b/u', $n) && preg_match('/\b(quien|quién|quien es|quién es)\b/u', $n)
        && !preg_match('/\b(mahaprabhu|caritamrita|caritamrta)\b/u', $n)) {
        return respuesta(
            "¿Te refieres a **Caitanya Mahaprabhu** (el maestro) o al término sánscrito **caitanya** (conciencia)?",
            IMG['atento']
        );
    }
    if (preg_match('/\b(lakshmi|laksmi)\b/u', $n) && preg_match('/\b(quien|quién|que es|qué es|diosa)\b/u', $n)) {
        return respuesta(
            "**Lakṣmī** (Lakshmi): diosa de la fortuna, la prosperidad, la belleza y la abundancia. "
            . "Se asocia con Śrī y, en muchas tradiciones, con Viṣṇu.\n\n"
            . "No debe confundirse con Kubera (custodio de tesoros): son deidades con funciones distintas.",
            IMG['base']
        );
    }
    if (preg_match('/\b(krishna|krsna)\b/u', $n) && preg_match('/\b(quien|quién|que es|qué es)\b/u', $n)) {
        return respuesta(
            "**Kṛṣṇa** (Krishna): en las tradiciones vaiṣṇavas es la Suprema Personalidad de Dios o un avatar de Viṣṇu, según la escuela. "
            . "Es el maestro de Arjuna en el *Bhagavad-gītā* y figura central del *Śrīmad-Bhāgavatam*.",
            IMG['base']
        );
    }
    if (preg_match('/\b(vishnu|visnu)\b/u', $n) && preg_match('/\b(quien|quién|que es|qué es)\b/u', $n)) {
        return respuesta(
            "**Viṣṇu** (Vishnu): en el hinduismo es la deidad que preserva el universo. Forma la trimūrti junto a Brahmā (creación) y Śiva (transformación). "
            . "Kṛṣṇa y Rāma se consideran, en muchas tradiciones, avatares de Viṣṇu.",
            IMG['base']
        );
    }
    if (preg_match('/\bprema\b/u', $n) && !preg_match('/\bsai\b/u', $n)
        && preg_match('/\b(que es|qué es|significa|significado|valor)\b/u', $n)) {
        return null;
    }
    return null;
}

function int_glosario(string $q, string $n): ?array {
    if (es_consulta_mundana($n)) return null;

    /* Música / géneros van a int_musica, no al glosario de ejemplos «Quiero…» */
    if (preg_match('/\b(musica|música|bhajan|mantra|kirtan|album|álbum|albumes|álbumes|playlist|sitar|bansuri)\b/u', $n)) {
        return null;
    }

    /* No robar nombres de maestros (ej. "sadh guru" → no devolver solo "guru") */
    if (match_persona($n) !== null) {
        return int_maestros($q, $n) ?? int_sai_baba($q, $n);
    }

    $g = cargar_glosario();
    if (!$g) return null;

    $x = null;
    $intencion_def = false;
    if (preg_match('/\b(significa|significado de|definicion de|definición de|que es|qué es|que son|qué son|quien es|quién es|quien fue|quién fue)\s+(.+)$/u', $n, $m)) {
        $x = trim($m[2]);
        $x = preg_replace('/^(la palabra|el termino|el término|una|un|la|el|los|las)\s+/u', '', $x);
        $intencion_def = true;
    }
    if (preg_match('/\b(diferencia|relacion|relación)\s+entre\s+(.+?)\s+(?:y|con)\s+(.+)$/u', $n, $m)) {
        [$t1, $p1] = buscar_en($g, norm($m[2]), 50);
        [$t2, $p2] = buscar_en($g, norm($m[3]), 50);
        if ($t1 && $t2) {
            $d1 = limpiar_def($g[$t1]);
            $d2 = limpiar_def($g[$t2]);
            return respuesta("Aquí está la relación que preguntas:\n\n• {$t1}: {$d1}\n\n• {$t2}: {$d2}", IMG['base']);
        }
    }

    $candidato = $x ?? $n;
    $min = $intencion_def ? 45 : (count(explode(' ', $n)) >= 5 ? 70 : 55);
    [$t, $p] = buscar_en($g, norm($candidato), $min);
    if ($t && $p) {
        $def = limpiar_def($g[$t]);
        if ($def === '') return null;
        return respuesta("{$t}: {$def}", IMG['base']);
    }
    return null;
}

/* ============================================================================
   8. NÚCLEO estilo YOCO (You Only Catch Once)
   ----------------------------------------------------------------------------
   Mitad de ABAJO  → analizar_consulta(): lee el prompt UNA sola vez y deja
                     un paquete de notas compartido ($notas).
   Mitad de ARRIBA → despachar_notas(): NO relee el prompt completo; trabaja
                     solo con esas notas y ejecuta UNA rama (con respaldos).
   ============================================================================ */

/**
 * Mitad inferior YOCO: una sola lectura del prompt → carpeta de apuntes.
 * No genera la respuesta; solo clasifica.
 */
function analizar_consulta(string $q, string $n): array {
    $notas = [
        'q'              => $q,
        'n'              => $n,
        'intencion'      => 'desconocida',
        'entidad'        => null,
        'es_mundana'     => false,
        'es_actualidad'  => false,
        'no_wiki'        => false,
        'pide_libros'    => false,
        'pide_imagen'    => false,
        'musica_kw'      => false,
        'necesidad_relax'=> false,
        'fallback'       => [],   // ramas de respaldo si la principal falla
    ];

    /* Flags baratos (una sola pasada de regex agrupados) */
    $notas['es_mundana']    = es_consulta_mundana($n);
    $notas['es_actualidad'] = es_consulta_actualidad($n);
    $notas['no_wiki']       = es_consulta_no_wiki_primero($n);
    $notas['pide_libros']   = (bool)preg_match('/\b(libro|libros|leer|lectura|descarga|pdf|documento|material|materiales|biblioteca|vahini|bhajanavali|discurso|carta)\b/u', $n);
    $notas['pide_imagen']   = (bool)preg_match('/\b(imagen|foto|fotografia|retrato|muestrame|muéstrame)\b/u', $n);
    $notas['musica_kw']     = (bool)preg_match('/\b(musica|musicas|cancion|canciones|album|albumes|escuchar|escucho|letra|radio|podcast|bhajan|bhajans|mantra|mantras|sonido|playlist|reproducir|genero|género|kirtan|raga|bansuri|sitar|gregoriano|bollywood|new age|hindustani|carnatic)\b/u', $n);
    $notas['necesidad_relax'] = (bool)preg_match('/\b(dormir|sueno|sueño|descansar|insomnio|relajar|relajacion|relajarse|meditar|meditacion|estudiar|estudio|concentrar|yoga|devocion|devocional)\b/u', $n);

    /* --- Clasificación por prioridad (solo una gana como intencion principal) --- */

    /* 0. Matemáticas / calculadora local (antes que externa) */
    if (preg_match('/\b(cuanto\s+es|cuánto\s+es|calcula|calcular|resuelve|resultado\s+de|math|matematica|matemática)\b/u', $n)
        || (preg_match('/^[\d\s+\-*\/().%^×÷]+$/u', trim($q)) && preg_match('/\d/', $q) && preg_match('/[+\-*\/×÷^%]/', $q))) {
        $notas['intencion'] = 'math';
        return $notas;
    }

    /* 1. Saludo / despedida / gracias */
    if (preg_match('/^(hola|holaa+|buenas|buenos dias|buen dia|buenas tardes|buenas noches|namaste|namasté|saludos|hi|hello|hey)\b/u', $n)
        || (preg_match('/\b(gracias|te lo agradezco|adios|adiós|chao|hasta luego|nos vemos|bye)\b/u', $n) && mb_strlen($n, 'UTF-8') < 40)) {
        $notas['intencion'] = 'saludo';
        return $notas;
    }

    /* 2. Identidad del agente */
    if (preg_match('/\b(quien eres|que eres|como te llamas|tu nombre|presentate|preséntate|quien es apsara|que puedes hacer|en que puedes ayudar|ayuda|funciones|capacidades|como funcionas|que es una apsara|apsaras)\b/u', $n)) {
        $notas['intencion'] = 'identidad';
        return $notas;
    }

    /* 3. Música / géneros / necesidad de sonido (antes que glosario) */
    if ($notas['musica_kw']
        || ($notas['necesidad_relax'] && preg_match('/\b(quiero|necesito|busco|recomend|ponme|dame|para)\b/u', $n))
        || (strpos($n, 'vimana') !== false && preg_match('/\b(musica|album|cancion|temas)\b/u', $n))
        || preg_match('/\b(musica india|música india|canto gregoriano|cuencos tibetanos)\b/u', $n)) {
        $notas['intencion'] = 'musica';
        $notas['fallback']  = ['vimana'];
        return $notas;
    }

    /* 4a. Lista de maestros (antes de glosario/Wikipedia de “maestro”) */
    if (preg_match('/\b(otros maestros|maestros espirituales|lista de maestros|que maestros|qué maestros|maestros de vimana|maestros que conoces|hablame de maestros|háblame de maestros|quiero saber sobre.*maestros|sobre los maestros|los maestros)\b/u', $n)
        && !preg_match('/\b(musica|música|orquesta|opera|ópera)\b/u', $n)) {
        $notas['intencion'] = 'lista_maestros';
        $notas['fallback']  = [];
        return $notas;
    }

    /* 4b. Sai Baba (antes que valores "sathya" y que mundana) */
    if (preg_match('/\b(sai baba|saibaba|sathya sai|sathia sai|sathi sai|satya sai|prema sai|shirdi)\b/u', $n)
        || (preg_match('/\b(quien|quién)\s+(es|era|fue)\s+(sathya|sathia|sathi|satya|prema)\b/u', $n)
            && !preg_match('/\b(valor|verdad|amor)\b/u', $n))) {
        $notas['intencion'] = 'sai_baba';
        $notas['entidad']   = 'sathya_sai_baba';
        $notas['fallback']  = $notas['pide_libros'] ? ['biblioteca'] : ['maestros', 'glosario'];
        return $notas;
    }

    /* 4c. Otros maestros de PERSONAS (match flexible: "sadh guru" → sadhguru) */
    $persona_key = match_persona($n);
    if ($persona_key !== null && $persona_key !== 'sathya_sai_baba') {
        $notas['intencion'] = 'maestro';
        $notas['entidad']   = $persona_key;
        $notas['fallback']  = $notas['pide_libros'] ? ['biblioteca'] : [];
        return $notas;
    }
    if ($persona_key === 'sathya_sai_baba') {
        $notas['intencion'] = 'sai_baba';
        $notas['entidad']   = 'sathya_sai_baba';
        $notas['fallback']  = $notas['pide_libros'] ? ['biblioteca'] : [];
        return $notas;
    }

    /* 5. Entidades clave (Caitanya, Lakshmi, Krishna…) */
    if (preg_match('/\b(caitanya|chaitanya|caitania|lakshmi|laksmi|krishna|krsna)\b/u', $n)) {
        $notas['intencion'] = 'entidad_clave';
        $notas['fallback']  = ['glosario'];
        return $notas;
    }

    /* 6. Valores Humanos / Educare */
    if (preg_match('/\b(valores humanos|valor humano|educare)\b/u', $n)
        || (preg_match('/\b(sathya|dharma|shanti|prema|ahimsa)\b/u', $n)
            && preg_match('/\b(valor|valores|que es|qué es|significa|significado)\b/u', $n)
            && !preg_match('/\bsai\b/u', $n))) {
        $notas['intencion'] = 'valores';
        $notas['fallback']  = $notas['pide_libros'] ? ['biblioteca'] : [];
        return $notas;
    }

    /* 7. Vimana (proyecto / sitio) */
    if (strpos($n, 'vimana') !== false) {
        $notas['intencion'] = 'vimana';
        $notas['fallback']  = ['musica'];
        return $notas;
    }

    /* 8. Biblioteca explícita */
    if ($notas['pide_libros'] || preg_match('/\b(biblioteca|vahini|vahinis|bhajanavali|discursos?|cartas?)\b/u', $n)) {
        $notas['intencion'] = 'biblioteca';
        $notas['fallback']  = [];
        return $notas;
    }

    /* 9. Mundana / actualidad → externa (no carga glosario) */
    if ($notas['es_mundana'] || $notas['es_actualidad'] || $notas['no_wiki']) {
        $notas['intencion'] = 'externa';
        $notas['fallback']  = [];
        return $notas;
    }

    /* 10. Glosario (términos sánscritos / sabiduría local) */
    if (preg_match('/\b(que es|qué es|que significa|qué significa|significado|definicion|definición|quien es|quién es|quien fue|quién fue)\b/u', $n)
        || preg_match('/\b(sanskrito|sánscrito|vedanta|yoga|mantra|dharma|karma|moksha|atman|brahman|bhakti|jnana|samadhi|om|aum)\b/u', $n)) {
        $notas['intencion'] = 'glosario';
        $notas['fallback']  = ['externa'];
        return $notas;
    }

    /* 11. Si merece externa por forma de pregunta */
    if (merece_externa($n)) {
        $notas['intencion'] = 'externa';
        $notas['fallback']  = ['glosario'];
        return $notas;
    }

    /* 12. Por defecto: intentar glosario local, luego externa */
    $notas['intencion'] = 'glosario';
    $notas['fallback']  = ['externa'];
    return $notas;
}

/**
 * Ejecuta búsqueda externa y formatea respuesta (sin re-analizar el prompt).
 */
function responder_externa(string $q, string $n): ?array {
    $GLOBALS['red_fallo'] = false;
    $ext = buscar_externo($q);
    $yurl = function_exists('url_yahoo_search') ? url_yahoo_search($q) : ('https://search.yahoo.com/search?p=' . rawurlencode($q));
    if ($ext) {
        $links = [];
        if (!empty($ext['links']) && is_array($ext['links'])) {
            foreach ($ext['links'] as $L) {
                if (!empty($L['u']) && preg_match('#^https?://#i', $L['u'])) {
                    $links[] = ['t' => (string)($L['t'] ?? $L['u']), 'u' => (string)$L['u']];
                }
            }
        } elseif (!empty($ext['url']) && preg_match('#^https?://#i', $ext['url'])) {
            $links[] = ['t' => 'Leer más en ' . ($ext['fuente'] ?? 'la web'), 'u' => $ext['url']];
        }
        /* Siempre ofrecer Yahoo como contingencia */
        $links[] = ['t' => 'Buscar en Yahoo', 'u' => $yurl];
        $fuente = (string)($ext['fuente'] ?? 'la web');
        if ($fuente === 'SearXNG') {
            $prefijo = '';
        } else {
            $prefijo = "No tengo esa información en mi base de conocimiento, pero realicé una búsqueda externa ({$fuente}) y encontré esto:\n\n";
        }
        return respuesta($prefijo . $ext['texto'], IMG['buscando'], $links, [], 'buscando');
    }
    if (!empty($GLOBALS['red_fallo'])) {
        return respuesta(
            "No encontré esa información en el repositorio de Vimana, y en este momento no puedo conectarme a internet desde el servidor para buscarla.\n\nPuedes intentarlo de nuevo más tarde o usar el botón de Yahoo.",
            IMG['reconectando'],
            [['t' => 'Buscar en Yahoo', 'u' => $yurl]]
        );
    }
    return null;
}

/**
 * Mitad superior YOCO: trabaja solo con $notas; no relee el prompt.
 * Ejecuta la rama principal y, si falla, los fallbacks indicados en las notas.
 */
function despachar_notas(array $notas): array {
    $q = $notas['q'];
    $n = $notas['n'];
    $resp = null;

    switch ($notas['intencion']) {
        case 'math':
            $resp = int_math($q, $n);
            break;

        case 'saludo':
            $resp = int_saludos($q, $n);
            break;

        case 'identidad':
            $resp = int_identidad($q, $n);
            break;

        case 'musica':
            $resp = int_musica($q, $n);
            break;

        case 'sai_baba':
            $resp = int_sai_baba($q, $n);
            if ($resp === null && $notas['pide_libros']) {
                $resp = int_biblioteca($q, $n);
            }
            break;

        case 'maestro':
            $resp = int_maestros($q, $n);
            break;

        case 'lista_maestros':
            $resp = int_lista_maestros($q, $n);
            break;

        case 'entidad_clave':
            $resp = int_entidades_clave($q, $n);
            break;

        case 'valores':
            $resp = int_valores($q, $n);
            break;

        case 'vimana':
            $resp = int_vimana($q, $n);
            break;

        case 'biblioteca':
            $resp = int_biblioteca($q, $n);
            break;

        case 'glosario':
            /* Solo carga glosario si la intención lo pide */
            $resp = int_glosario($q, $n);
            break;

        case 'externa':
            $resp = responder_externa($q, $n);
            break;
    }

    /* Respaldo: solo las ramas listadas en notas (no toda la cascada) */
    if ($resp === null && !empty($notas['fallback'])) {
        foreach ($notas['fallback'] as $rama) {
            if ($resp !== null) break;
            switch ($rama) {
                case 'musica':     $resp = int_musica($q, $n); break;
                case 'vimana':     $resp = int_vimana($q, $n); break;
                case 'maestros':   $resp = int_maestros($q, $n); break;
                case 'biblioteca': $resp = int_biblioteca($q, $n); break;
                case 'glosario':   $resp = int_glosario($q, $n); break;
                case 'externa':    $resp = responder_externa($q, $n); break;
            }
        }
    }

    /* Último recurso: externa si merece, si no Yahoo */
    if ($resp === null && (merece_externa($n) || $notas['es_mundana'] || $notas['no_wiki'])) {
        $resp = responder_externa($q, $n);
    }

    if ($resp === null) {
        $yurl = url_yahoo_search($q);
        $msg = $notas['no_wiki']
            ? "Esa consulta (ciencia, software, medicina, alimentos, deportes, noticias, etc.) no está en mi base local de Vimana.\n\nPulsa el botón para buscarla actualizada en Yahoo."
            : "No tengo esa información en mi base de conocimiento, pero encontré algo relacionado con el tema.\n\nPulsa el botón de abajo para abrir la búsqueda.";
        $resp = respuesta($msg, IMG['pensando'], [['t' => 'Buscar en Yahoo', 'u' => $yurl]]);
    }

    return $resp;
}

/* ----------------------------------------------------------------------------
   Entrada HTTP → YOCO: analizar una vez → despachar
   ---------------------------------------------------------------------------- */

$input_raw = file_get_contents('php://input');
$data = json_decode($input_raw, true) ?? $_POST;

$q = esc((string)($data['q'] ?? $data['pregunta'] ?? ''));

if ($q === '') {
    salida(respuesta("Hola 🌺, soy Apsara Vimana. ¿En qué puedo ayudarte hoy?", IMG['base']));
}
if (mb_strlen($q, 'UTF-8') > MAX_INPUT) {
    salida(respuesta("Tu consulta es un poco extensa. Por favor, realiza una pregunta más corta.", IMG['pensando']));
}
if (!rate_limit()) {
    salida(respuesta("He recibido varias consultas seguidas. Hagamos una breve pausa.", IMG['suspendido']));
}

limpieza_cache();

/* Mitad de abajo: una sola lectura → notas */
$n     = norm($q);
$notas = analizar_consulta($q, $n);

/* Mitad de arriba: trabaja solo con las notas */
$resp = despachar_notas($notas);

salida($resp);
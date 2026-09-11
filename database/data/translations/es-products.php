<?php

/**
 * Spanish copy for the products, keyed by `ProductKey` value.
 *
 * Loaded by `SpanishContentSeeder`, which is deliberately not registered in
 * `DatabaseSeeder`. See that class for why.
 *
 * **The English these were translated from is `ProductKey::defaultName()`,
 * `defaultShortDescription()` and `defaultLongDescription()`.** If Jennifer has
 * since reworded a product in the panel, the English on the row and the Spanish
 * here no longer describe the same thing, and this file is the one that is
 * wrong. The seeder cannot notice that, since it only sees whether Spanish is
 * blank.
 *
 * **Names are in capitals because the English names are**, and for the same
 * reason: `ProductCard` prints the name as given and there is no
 * `text-transform` anywhere in the frontend's CSS, so anything else here would
 * render a Spanish homepage that shouts in English and murmurs in Spanish. The
 * docblock on `defaultName()` makes the same argument at length.
 *
 * @return array<string, array<string, string>>
 */
return [
    'one-card' => [
        'name' => 'LECTURA DE 1 CARTA',
        'short_description' => 'Un Solo Mensaje del Tarot',
        'long_description' => 'Una carta, sacada para tu pregunta y leída por completo. Un mensaje concentrado para cuando quieres claridad sobre una sola situación y no un mapa de todo lo que la rodea. Tu lectura llega presentada sobre la ilustración original y se entrega por correo electrónico.',
    ],

    'three-card' => [
        'name' => 'LECTURA DE 3 CARTAS',
        'short_description' => 'Una Pregunta, Tres Cartas',
        'long_description' => 'Tres cartas leídas como una sola historia: qué hay detrás de tu pregunta, qué la rodea ahora y hacia dónde se dirige. La tirada que la mayoría imagina cuando piensa en el tarot, y la profundidad justa para una pregunta que llevas tiempo cargando.',
    ],

    'month-ahead' => [
        // "MONTH AHEAD" has no compact Spanish equivalent that fits a tile.
        // "EL MES QUE VIENE" is the phrase a Spanish speaker would actually
        // use; a literal "MES POR DELANTE" reads as translated-from-English.
        'name' => 'EL MES QUE VIENE',
        'short_description' => '¿Qué te Espera? Pronóstico de 5 Cartas',
        'long_description' => 'Cinco cartas para el mes que tienes por delante: su forma, su punto de inflexión y lo que te está pidiendo. Un pronóstico para guardar y volver a él conforme pasan las semanas, más que una respuesta a una sola pregunta.',
    ],

    'in-depth' => [
        // ~~"LECTURA EN PROFUNDIDAD"~~ until 11 September 2026. It set two
        // lines on the readings index where every other name sets one, which
        // pushed that card's copy below its neighbours'. "A FONDO" is the same
        // idiom for "in depth", in fewer letters.
        'name' => 'LECTURA A FONDO',
        'short_description' => 'La Lectura Más Completa, Carta por Carta',
        'long_description' => 'La lectura más completa que se ofrece aquí: una interpretación larga y sin prisa que desarma tu pregunta y sigue cada hilo que abren las cartas. Para las preguntas que merecen más que una tirada y un resumen.',
    ],

    'viewing-room-pass' => [
        'name' => 'SALA DE PROYECCIÓN',
        // ~~"Colección Cinematográfica Completa"~~ until 11 September 2026: the
        // homepage tile holds two lines and cut it off with an ellipsis.
        'short_description' => 'Colección de Cine Completa',
        'long_description' => 'Un pase a la Sala de Proyección, donde los veintidós Arcanos Mayores se proyectan como las películas que estaban destinadas a ser, cada una rodada en el lugar que la inspiró. Tuyo para volver siempre que quieras.',
    ],
];

<?php
/**
 * Загрузка логотипов SVG и файлов шрифтов в медиатеку (2.9.0).
 *
 * SVG и шрифты WOFF2, WOFF, TTF, OTF разрешены только администраторам (право manage_options).
 * SVG при загрузке очищается: остаются только элементы и атрибуты графики, удаляются скрипты, обработчики событий,
 * внешние ссылки, DOCTYPE и сущности. Файл, который не удалось разобрать, не загружается.
 * Шрифт проверяется по сигнатуре файла: переименованный файл другого типа не пройдёт.
 */

defined('ABSPATH') || exit;

final class WPD_Uploads
{
    /** Типы файлов, которые добавляются администраторам. */
    const MIMES = [
        'svg'   => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'woff'  => 'font/woff',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
    ];

    /** Сигнатуры файлов шрифтов (первые байты). */
    const FONT_MAGIC = [
        'woff2' => ['wOF2'],
        'woff'  => ['wOFF'],
        'ttf'   => ["\x00\x01\x00\x00", 'true', 'ttcf'],
        'otf'   => ['OTTO', "\x00\x01\x00\x00"],
    ];

    /** Наибольший размер SVG, байт. */
    const SVG_MAX = 2097152;

    /** Элементы SVG, которые остаются после очистки (в нижнем регистре). */
    const SVG_ELEMENTS = [
        'svg', 'g', 'defs', 'desc', 'title', 'metadata', 'symbol', 'use', 'image', 'switch', 'view', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textpath',
        'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'marker', 'filter',
        'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feconvolvematrix', 'fediffuselighting',
        'fedisplacementmap', 'fedistantlight', 'fedropshadow', 'feflood', 'fefunca', 'fefuncb', 'fefuncg', 'fefuncr',
        'fegaussianblur', 'feimage', 'femerge', 'femergenode', 'femorphology', 'feoffset', 'fepointlight',
        'fespecularlighting', 'fespotlight', 'fetile', 'feturbulence',
        'animate', 'animatetransform', 'animatemotion', 'set', 'mpath',
    ];

    const NS_SVG   = 'http://www.w3.org/2000/svg';
    const NS_XLINK = 'http://www.w3.org/1999/xlink';
    const NS_XML   = 'http://www.w3.org/XML/1998/namespace';

    public static function init(): void
    {
        add_filter('upload_mimes', [__CLASS__, 'upload_mimes'], 10, 2);
        add_filter('wp_check_filetype_and_ext', [__CLASS__, 'check_filetype'], 10, 5);
        add_filter('wp_handle_upload_prefilter', [__CLASS__, 'prefilter']);
        add_filter('wp_handle_sideload_prefilter', [__CLASS__, 'prefilter']);
        add_filter('wp_generate_attachment_metadata', [__CLASS__, 'svg_metadata'], 10, 2);
        add_filter('wp_prepare_attachment_for_js', [__CLASS__, 'svg_for_js'], 10, 3);
    }

    /** Может ли пользователь загружать SVG и шрифты. */
    public static function allowed($user = null): bool
    {
        if ($user instanceof WP_User || (is_numeric($user) && (int) $user > 0)) {
            return user_can($user, 'manage_options');
        }
        return current_user_can('manage_options');
    }

    /**
     * @param array            $mimes
     * @param int|WP_User|null $user
     */
    public static function upload_mimes($mimes, $user = null): array
    {
        $mimes = is_array($mimes) ? $mimes : [];
        if (self::allowed($user)) {
            foreach (self::MIMES as $ext => $type) {
                $mimes[$ext] = $type;
            }
        }
        return $mimes;
    }

    /** Расширение файла в нижнем регистре. */
    private static function ext(string $name): string
    {
        return strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    }

    /**
     * WordPress сверяет тип по содержимому и для SVG и шрифтов часто получает text/plain или application/octet-stream.
     * Для администратора тип берётся по расширению, если содержимое похоже на этот тип.
     *
     * @param array       $data
     * @param string      $file
     * @param string      $filename
     * @param array|null  $mimes
     * @param string|bool $real_mime
     */
    public static function check_filetype($data, $file, $filename, $mimes = null, $real_mime = false): array
    {
        $data = is_array($data) ? $data : [];
        $ext  = self::ext((string) $filename);
        if (!isset(self::MIMES[$ext]) || !self::allowed()) {
            return $data;
        }
        $looks = $ext === 'svg' ? self::looks_like_svg((string) $file) : self::font_signature_ok((string) $file, $ext);
        if ($looks) {
            $data['ext']  = $ext;
            $data['type'] = self::MIMES[$ext];
            $data['proper_filename'] = $data['proper_filename'] ?? false;
        }
        return $data;
    }

    /**
     * Перед сохранением загрузки: SVG очищается (или отклоняется), шрифт проверяется по сигнатуре.
     *
     * @param array $file name, tmp_name, …
     */
    public static function prefilter($file): array
    {
        $file = is_array($file) ? $file : [];
        $ext  = self::ext((string) ($file['name'] ?? ''));
        if (!isset(self::MIMES[$ext]) || !empty($file['error'])) {
            return $file;
        }
        if (!self::allowed()) {
            $file['error'] = 'Загружать SVG и файлы шрифтов могут только администраторы.';
            return $file;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            return $file;
        }
        if ($ext === 'svg') {
            $raw   = (string) file_get_contents($tmp); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $clean = self::sanitize_svg($raw);
            if ($clean === null) {
                $file['error'] = 'SVG не прошёл проверку безопасности: файл повреждён, слишком большой или содержит недопустимые конструкции (DOCTYPE, сущности).';
                return $file;
            }
            file_put_contents($tmp, $clean); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $file['size'] = strlen($clean);
            return $file;
        }
        if (!self::font_signature_ok($tmp, $ext)) {
            $file['error'] = 'Файл не похож на шрифт ' . strtoupper($ext) . '. Загрузите настоящий файл WOFF2, WOFF, TTF или OTF.';
        }
        return $file;
    }

    /** Совпадает ли начало файла с сигнатурой шрифта этого типа. */
    public static function font_signature_ok(string $path, string $ext): bool
    {
        if (!isset(self::FONT_MAGIC[$ext]) || !is_readable($path)) {
            return false;
        }
        $head = (string) file_get_contents($path, false, null, 0, 4); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return in_array($head, self::FONT_MAGIC[$ext], true);
    }

    /** Похоже ли содержимое на SVG: в начале файла есть тег <svg. */
    private static function looks_like_svg(string $path): bool
    {
        if (!is_readable($path)) {
            return false;
        }
        $head = (string) file_get_contents($path, false, null, 0, 4096); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return stripos($head, '<svg') !== false;
    }

    /**
     * Очистка SVG. null — файл отклонён (не разобрался, нет корня svg, есть DOCTYPE с сущностями, слишком большой).
     */
    public static function sanitize_svg(string $svg): ?string
    {
        if ($svg === '' || strlen($svg) > self::SVG_MAX || !class_exists('DOMDocument')) {
            return null;
        }
        if (substr($svg, 0, 2) === "\x1f\x8b") {
            return null; // сжатый SVGZ не принимаем
        }
        $svg = (string) preg_replace('/^\xEF\xBB\xBF/', '', $svg);
        // Сущности могут раздуть файл или прочитать локальные файлы — такой SVG не принимаем
        if (preg_match('/<!ENTITY/i', $svg)) {
            return null;
        }
        $svg = (string) preg_replace('/<!DOCTYPE[^>\[]*(\[[^\]]*\])?\s*>/i', '', $svg);

        $prev_loader = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $prev_loader = libxml_disable_entity_loader(true); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions
        }
        // Ошибки разбора просто подавляем: список ошибок libxml не трогаем (его очистка роняет некоторые сборки PHP,
        // например WebAssembly), а чужие настройки libxml_use_internal_errors не меняем
        $dom = new DOMDocument();
        $ok  = @$dom->loadXML($svg, LIBXML_NONET); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if ($prev_loader !== null) {
            libxml_disable_entity_loader($prev_loader); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions
        }
        $root = $ok ? $dom->documentElement : null;
        if (!$root || strtolower($root->localName) !== 'svg' || $dom->doctype) {
            return null;
        }
        if (!$root->namespaceURI) {
            $root->setAttribute('xmlns', self::NS_SVG);
        }

        self::clean_node($root);

        // Размеры из viewBox, если их нет: иначе <img> со своей высотой может получить нулевую ширину
        $box = preg_split('/[\s,]+/', trim((string) $root->getAttribute('viewBox')));
        if (count($box) === 4 && (float) $box[2] > 0 && (float) $box[3] > 0
            && $root->getAttribute('width') === '' && $root->getAttribute('height') === '') {
            $root->setAttribute('width', (string) (float) $box[2]);
            $root->setAttribute('height', (string) (float) $box[3]);
        }

        $out = $dom->saveXML($root);
        return is_string($out) && $out !== '' ? '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out . "\n" : null;
    }

    /** Значение без пробелов и управляющих символов в нижнем регистре — для поиска «javascript:» и подобного. */
    private static function squash(string $v): string
    {
        return strtolower((string) preg_replace('/[\s\x00-\x1f]+|&#[0-9a-fx]+;?/i', '', $v));
    }

    /** Опасное значение атрибута или стиля. */
    private static function dangerous(string $v): bool
    {
        $s = self::squash($v);
        return strpos($s, 'javascript:') !== false || strpos($s, 'vbscript:') !== false || strpos($s, 'data:text') !== false
            || strpos($s, 'expression(') !== false || strpos($s, '@import') !== false || strpos($s, '-moz-binding') !== false
            || (bool) preg_match('/url\((?![\'"]?#)/', $s);
    }

    /** Ссылка href допустима: якорь внутри файла или встроенная растровая картинка. */
    private static function safe_href(string $v): bool
    {
        $s = self::squash($v);
        return $s === '' || $s[0] === '#' || (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $s);
    }

    private static function clean_node(DOMElement $el): void
    {
        // Атрибуты
        $remove = [];
        foreach (iterator_to_array($el->attributes) as $attr) {
            /** @var DOMAttr $attr */
            $name = strtolower($attr->localName);
            $ns   = (string) $attr->namespaceURI;
            if ($ns !== '' && $ns !== self::NS_XLINK && $ns !== self::NS_XML) {
                $remove[] = $attr; // атрибуты редакторов (inkscape:, sodipodi:) и чужих пространств имён
            } elseif (strpos($name, 'on') === 0) {
                $remove[] = $attr; // обработчики событий
            } elseif ($name === 'href') {
                if (!self::safe_href($attr->value)) {
                    $remove[] = $attr;
                }
            } elseif (self::dangerous($attr->value)) {
                $remove[] = $attr;
            }
        }
        foreach ($remove as $attr) {
            $el->removeAttributeNode($attr);
        }

        // Анимация, которая подменяет ссылку или обработчик, удаляется целиком
        $tag = strtolower($el->localName);
        if (in_array($tag, ['animate', 'set', 'animatetransform', 'animatemotion'], true)) {
            $target = strtolower(trim($el->getAttribute('attributeName')));
            if ($target !== '' && (strpos($target, 'href') !== false || strpos($target, 'on') === 0)) {
                $el->parentNode->removeChild($el);
                return;
            }
        }

        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $ns = (string) $child->namespaceURI;
                if (($ns !== '' && $ns !== self::NS_SVG) || !in_array(strtolower($child->localName), self::SVG_ELEMENTS, true)) {
                    $el->removeChild($child); // script, foreignObject, iframe, элементы других пространств имён
                    continue;
                }
                if (strtolower($child->localName) === 'style' && self::dangerous($child->textContent)) {
                    $el->removeChild($child);
                    continue;
                }
                self::clean_node($child);
            } elseif ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction || $child instanceof DOMEntityReference) {
                $el->removeChild($child);
            }
        }
    }

    /** Ширина и высота SVG из атрибутов или viewBox. */
    public static function svg_size(string $path): array
    {
        if (!is_readable($path)) {
            return [0, 0];
        }
        $head = (string) file_get_contents($path, false, null, 0, 8192); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if (!preg_match('/<svg\b[^>]*>/is', $head, $m)) {
            return [0, 0];
        }
        $attr = function (string $name) use ($m): string {
            return preg_match('/\s' . $name . '\s*=\s*["\']([^"\']*)["\']/i', $m[0], $mm) ? trim($mm[1]) : '';
        };
        $w = $attr('width');
        $h = $attr('height');
        if (preg_match('/^[\d.]+(px)?$/', $w) && preg_match('/^[\d.]+(px)?$/', $h)) {
            return [(int) round((float) $w), (int) round((float) $h)];
        }
        $box = preg_split('/[\s,]+/', $attr('viewBox'));
        if (count($box) === 4) {
            return [(int) round((float) $box[2]), (int) round((float) $box[3])];
        }
        return [0, 0];
    }

    /**
     * @param array $metadata
     * @param int   $attachment_id
     */
    public static function svg_metadata($metadata, $attachment_id): array
    {
        $metadata = is_array($metadata) ? $metadata : [];
        if (get_post_mime_type((int) $attachment_id) !== 'image/svg+xml') {
            return $metadata;
        }
        $path = (string) get_attached_file((int) $attachment_id);
        list($w, $h) = self::svg_size($path);
        if ($w > 0 && $h > 0) {
            $metadata['width']  = $w;
            $metadata['height'] = $h;
        }
        if (empty($metadata['file'])) {
            $metadata['file'] = (string) _wp_relative_upload_path($path);
        }
        return $metadata;
    }

    /**
     * Превью SVG в медиатеке: размер «полный» с адресом самого файла.
     *
     * @param array   $response
     * @param WP_Post $attachment
     * @param mixed   $meta
     */
    public static function svg_for_js($response, $attachment, $meta): array
    {
        $response = is_array($response) ? $response : [];
        if (($response['mime'] ?? '') !== 'image/svg+xml' || !empty($response['sizes'])) {
            return $response;
        }
        $w = (int) ($response['width'] ?? 0) ?: 512;
        $h = (int) ($response['height'] ?? 0) ?: 512;
        $response['sizes'] = ['full' => ['url' => (string) ($response['url'] ?? ''), 'width' => $w, 'height' => $h,
                                         'orientation' => $w >= $h ? 'landscape' : 'portrait']];
        return $response;
    }
}

<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Converter;

\defined('_JEXEC') or die;

/**
 * Writes a JPEG or PNG as AVIF or WebP, with what the server has: GD, Imagick, or a program that
 * the administrator named.
 *
 * A PNG is written as WebP twice, with and without loss, and the smaller file is the one that
 * stays: what is drawn, like a picture of a screen, is often smaller without loss, and exact.
 *
 * The program is one of avifenc, cwebp and magick. It is started without a shell, with the paths
 * as arguments of their own: no name of a file can become a command. The paths are absolute, so
 * none starts with a dash and reads as an option. Only what is a JPEG or a PNG by its content is
 * given to it.
 */
final class Encoder
{
    public const GD      = 'gd';
    public const IMAGICK = 'imagick';
    public const COMMAND = 'command';

    /** Seconds that the program may take for one image */
    private const TIMEOUT = 120;

    /**
     * @param  string  $binary  Path of the program. Empty for none.
     */
    public function __construct(private string $binary = '')
    {
        $this->binary = trim($binary);
    }

    /**
     * What can write which format, the first choice first.
     *
     * @return  array<string, string[]>  Format => encoders
     */
    public function capabilities(): array
    {
        $can = ['avif' => [], 'webp' => []];

        if (\function_exists('imagecreatefromjpeg') && \function_exists('imagecreatefrompng')) {
            if (\function_exists('imageavif')) {
                $can['avif'][] = self::GD;
            }

            if (\function_exists('imagewebp')) {
                $can['webp'][] = self::GD;
            }
        }

        if (class_exists(\Imagick::class)) {
            try {
                $formats = \Imagick::queryFormats();

                foreach (['avif' => 'AVIF', 'webp' => 'WEBP'] as $format => $name) {
                    if (\in_array($name, $formats, true)) {
                        $can[$format][] = self::IMAGICK;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        foreach ($this->programFormats() as $format) {
            $can[$format][] = self::COMMAND;
        }

        return $can;
    }

    public function can(string $format): bool
    {
        return !empty($this->capabilities()[$format]);
    }

    /**
     * @param   string  $source     File to read.
     * @param   string  $target     File to write. Its name ends in the format.
     * @param   int     $quality    1 to 100.
     * @param   int     $maxPixels  Images with more pixels are left alone: they take more memory
     *                              than a request has.
     *
     * @return  string  The encoder that wrote the file.
     *
     * @throws  \RuntimeException  When the file could not be written. Nothing is left of it then.
     */
    /**
     * Seconds that an external program may take for one image. Saving content gives less.
     */
    private int $timeout = self::TIMEOUT;

    /**
     * @param  int  $seconds  At most this long for the program, for the images to come.
     */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(1, min(self::TIMEOUT, $seconds));
    }

    public function encode(string $source, string $target, string $format, int $quality, int $maxPixels = 40000000): string
    {
        $encoders = $this->capabilities()[$format] ?? [];

        if (!$encoders) {
            throw new \RuntimeException('This server cannot write ' . $format . '.');
        }

        $size = @getimagesize($source);

        if (!$size || !\in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new \RuntimeException('Not a JPEG or PNG image.');
        }

        if ($size[0] * $size[1] > $maxPixels) {
            throw new \RuntimeException('Too large: ' . $size[0] . ' x ' . $size[1] . ' pixels.');
        }

        $quality = max(1, min(100, $quality));
        $error   = null;

        foreach ($encoders as $encoder) {
            try {
                $this->write($encoder, $source, $target, $format, $quality, $size, false);

                if ($format === 'webp' && $size[2] === IMAGETYPE_PNG) {
                    $this->keepSmaller($encoder, $source, $target, $size);
                }

                return $encoder;
            } catch (\Throwable $e) {
                $error = $e;

                if (is_file($target)) {
                    @unlink($target);
                }
            }
        }

        throw new \RuntimeException($error ? $error->getMessage() : 'Could not write the image.');
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $size
     */
    private function write(string $encoder, string $source, string $target, string $format, int $quality, array $size, bool $lossless): void
    {
        match ($encoder) {
            self::GD      => $this->withGd($source, $target, $format, $quality, $size, $lossless),
            self::IMAGICK => $this->withImagick($source, $target, $format, $quality, $lossless),
            default       => $this->withProgram($source, $target, $quality, $lossless),
        };

        clearstatcache(true, $target);

        if (!self::isOf($target, $format)) {
            throw new \RuntimeException('What was written is not ' . $format . '.');
        }
    }

    /**
     * Writes the image as WebP without loss as well, and keeps it when it is the smaller one.
     *
     * @param  array{0: int, 1: int, 2: int}  $size
     */
    private function keepSmaller(string $encoder, string $source, string $target, array $size): void
    {
        if ($encoder === self::GD && !\defined('IMG_WEBP_LOSSLESS')) {
            return;
        }

        $exact = preg_replace('/\.webp$/', '', $target) . '-exact.webp';

        try {
            $this->write($encoder, $source, $exact, 'webp', 100, $size, true);

            if (filesize($exact) < filesize($target)) {
                rename($exact, $target);
                clearstatcache(true, $target);
            }
        } catch (\Throwable $e) {
            // The one with loss is there
        } finally {
            if (is_file($exact)) {
                @unlink($exact);
            }
        }
    }

    /**
     * Whether the file starts the way files of the format do.
     */
    public static function isOf(string $file, string $format): bool
    {
        $start = is_file($file) ? (string) @file_get_contents($file, false, null, 0, 16) : '';

        return $format === 'webp'
            ? str_starts_with($start, 'RIFF') && substr($start, 8, 4) === 'WEBP'
            : substr($start, 4, 4) === 'ftyp' && \in_array(substr($start, 8, 4), ['avif', 'avis'], true);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $size
     */
    private function withGd(string $source, string $target, string $format, int $quality, array $size, bool $lossless): void
    {
        // Four bytes for a pixel, and what GD needs to work
        $needed = (int) ($size[0] * $size[1] * 4 * 1.8);
        $limit  = self::bytes((string) \ini_get('memory_limit'));

        if ($limit > 0 && memory_get_usage() + $needed > $limit) {
            throw new \RuntimeException('Not enough memory for ' . $size[0] . ' x ' . $size[1] . ' pixels.');
        }

        $image = $size[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($source) : @imagecreatefrompng($source);

        if (!$image) {
            throw new \RuntimeException('GD could not read the image.');
        }

        try {
            if ($size[2] === IMAGETYPE_JPEG) {
                $image = self::upright($image, $source);
            } else {
                imagepalettetotruecolor($image);
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }

            $written = $format === 'avif'
                ? imageavif($image, $target, $quality)
                : imagewebp($image, $target, $lossless ? IMG_WEBP_LOSSLESS : $quality);

            if (!$written) {
                throw new \RuntimeException('GD could not write the image.');
            }
        } finally {
            // Freed when nothing holds it: imagedestroy() has done nothing since PHP 8.0, and is deprecated in 8.5
            unset($image);
        }
    }

    /**
     * Turns the image the way the camera says it is to be seen. Browsers do so for a JPEG; the new
     * file has nothing that says so.
     */
    private static function upright(\GdImage $image, string $source): \GdImage
    {
        $orientation = \function_exists('exif_read_data') ? (int) (@exif_read_data($source)['Orientation'] ?? 1) : 1;

        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $angle = [3 => 180, 5 => 90, 6 => 270, 7 => 270, 8 => 90][$orientation] ?? 0;

        if ($angle === 0) {
            return $image;
        }

        $turned = imagerotate($image, $angle, 0);

        if (!$turned) {
            return $image;
        }

        unset($image);

        return $turned;
    }

    private function withImagick(string $source, string $target, string $format, int $quality, bool $lossless): void
    {
        // Read as what its content is, whatever its name says
        $image = new \Imagick();

        $image->readImage(((@getimagesize($source)[2] ?? 0) === IMAGETYPE_PNG ? 'png:' : 'jpeg:') . $source);

        try {
            if (method_exists($image, 'autoOrient')) {
                $image->autoOrient();
            }

            $image->setImageFormat($format);
            $image->setImageCompressionQuality($quality);

            if ($lossless) {
                $image->setOption('webp:lossless', 'true');
            }

            if (!$image->writeImage($format . ':' . $target)) {
                throw new \RuntimeException('Imagick could not write the image.');
            }
        } finally {
            $image->clear();
        }
    }

    private function withProgram(string $source, string $target, int $quality, bool $lossless): void
    {
        $exact   = $lossless ? [['-lossless'], ['-define', 'webp:lossless=true']] : [[], []];
        $command = match ($this->program()) {
            'avifenc' => [$this->binary, '-q', (string) $quality, '-s', '6', '-j', '2', $source, $target],
            'cwebp'   => array_merge([$this->binary, '-quiet', '-q', (string) $quality, '-metadata', 'icc'], $exact[0], [$source, '-o', $target]),
            // Read as what its content is, whatever its name says: a name must not choose another reader,
            // which for this program can be one that runs what it reads
            'magick'  => array_merge(
                [$this->binary, (@getimagesize($source)[2] ?? 0) === IMAGETYPE_PNG ? 'png:' . $source : 'jpeg:' . $source, '-auto-orient', '-quality', (string) $quality],
                $exact[1],
                [(str_ends_with($target, '.avif') ? 'avif:' : 'webp:') . $target]
            ),
            default   => throw new \RuntimeException('The program is none of avifenc, cwebp and magick.'),
        };

        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!\is_resource($process)) {
            throw new \RuntimeException('The program could not be started.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output   = '';
        $deadline = microtime(true) + $this->timeout;

        do {
            $status = proc_get_status($process);

            // Read so that the program does not wait for a reader; kept as far as an error is told
            $output = substr($output . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]), -2000);

            if ($status['running']) {
                usleep(20000);
            }
        } while ($status['running'] && microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($process, 9);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($status['running']) {
            throw new \RuntimeException('The program took more than ' . $this->timeout . ' seconds.');
        }

        if ((int) $status['exitcode'] !== 0) {
            throw new \RuntimeException('The program failed: ' . mb_substr(trim(mb_scrub($output, 'UTF-8')), -200, null, 'UTF-8'));
        }
    }

    /**
     * @return  string  "avifenc", "cwebp", "magick", or nothing for no program that is known.
     */
    private function program(): string
    {
        $name = strtolower(pathinfo($this->binary, PATHINFO_FILENAME));

        return \in_array($name, ['avifenc', 'cwebp', 'magick'], true) ? $name : '';
    }

    /**
     * @return  string[]
     */
    private function programFormats(): array
    {
        if ($this->binary === '' || !\function_exists('proc_open') || !@is_file($this->binary) || !@is_executable($this->binary)) {
            return [];
        }

        return ['avifenc' => ['avif'], 'cwebp' => ['webp'], 'magick' => ['avif', 'webp']][$this->program()] ?? [];
    }

    private static function bytes(string $value): int
    {
        $value  = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1073741824,
            'm'     => $number * 1048576,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}

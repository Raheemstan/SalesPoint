<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The pictures a shop uploads: a product's photo and the shop's own logo.
 *
 * All of it is one small set of rules that were previously written out twice -
 * once in ProductController, once in SettingController - and had already drifted
 * apart (the logo accepted no WebP, had no dimension limit, and never deleted the
 * file it replaced). Four things live here:
 *
 *  - WHAT IS ACCEPTED (rules()): the type is decided by what the file contains,
 *    never by what it is called.
 *  - WHAT REACHES THE DISK (store()): a random name with an extension taken from
 *    the content, so no filename the browser sent is ever a path on this server.
 *  - WHETHER IT IS STILL THERE (url(), exists()): a row can name a file that is
 *    not on the disk - a restored database, a half-copied deploy - and a screen
 *    has to be able to ask before it prints an <img> that would break.
 *  - WHETHER IT CAN BE REACHED AT ALL (publicLinkMissing()): the one failure a
 *    shop actually hit, where everything is saved correctly and every picture is
 *    still a 403 because `php artisan storage:link` was never run.
 */
class MediaService
{
    /** Uploads go on the public disk; it is the only one with a URL. */
    public const DISK = 'public';

    /** A photo from a phone is 2-5 MB; 2 MB is plenty for a thumbnail of it. */
    public const MAX_KILOBYTES = 2048;

    /**
     * The widest picture either side, in pixels.
     *
     * Not a quality rule - 8000 px accepts every phone and camera photo there
     * is. It is there to refuse a decompression bomb: a 30000 x 30000 PNG of
     * one flat colour is a few hundred kilobytes on disk and 2.7 GB once
     * something opens it. Nothing here resizes an upload, but the browsers and
     * print paths that do would be the ones to fall over.
     *
     * There is deliberately no MINIMUM. A tiny picture is somebody's choice, not
     * a fault, and refusing one would only teach a shopkeeper that the form is
     * broken.
     */
    public const MAX_PIXELS = 8000;

    /**
     * The extensions we are willing to put on disk, keyed by what the file's own
     * content says it is. JPEG answers to two names and both mean .jpg.
     */
    private const EXTENSIONS = [
        'jpg' => 'jpg',
        'jpeg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
    ];

    /**
     * Rules for an uploaded picture.
     *
     * `image` and `mimes` both decide the type from the bytes of the file, not
     * from its name: Laravel asks the operating system what the file is
     * (finfo), so "shell.php" renamed to "logo.png" is refused here rather than
     * written to a web-reachable folder. SVG is not in the list on purpose -
     * it is a scriptable document that happens to draw, and Laravel 11 and
     * later leave it out of `image` for that reason.
     *
     * `dimensions` is the strongest check of the three, and the reason it is
     * worth its cost: it is the only rule that makes PHP actually parse the
     * image, so a file that is a valid PNG header with something else bolted on
     * does not get through.
     *
     * @return array<int, string>
     */
    public static function rules(int $maxKilobytes = self::MAX_KILOBYTES): array
    {
        return [
            'nullable',
            'image',
            'mimes:jpeg,jpg,png,webp',
            'max:' . $maxKilobytes,
            'dimensions:max_width=' . self::MAX_PIXELS . ',max_height=' . self::MAX_PIXELS,
        ];
    }

    /**
     * Messages for the two rules whose own wording means nothing to a shopkeeper.
     *
     * "The image has invalid image dimensions" is the default for a picture that
     * is simply too big, and reads like a bug in the app.
     *
     * @return array<string, string>
     */
    public static function messages(string $field = 'image'): array
    {
        return [
            $field . '.dimensions' => 'That picture is too big to use: ' . number_format(self::MAX_PIXELS)
                . ' pixels is the most on either side.',
            $field . '.uploaded' => 'That picture did not finish uploading. It may be too large for this server.',
        ];
    }

    /**
     * Save an upload and return the path to store in the database.
     *
     * @throws RuntimeException when the file cannot be written, or is not a type we store
     */
    public function store(UploadedFile $file, string $folder): string
    {
        $name = $this->filename($file);

        $this->ensureDirectory($folder);

        $path = $file->storeAs($folder, $name, self::DISK);

        /*
         * The public disk is configured with `throw => false`, so a write that
         * fails - storage/app/public not writable, a full disk - comes back as a
         * bare false with no reason attached. Unchecked, that false is what gets
         * saved as the image path, and the product ends up pointing at nothing
         * while the screen says the save succeeded.
         */
        if (!is_string($path) || $path === '') {
            throw new RuntimeException(
                'The picture could not be saved. Check that storage/app/public exists and is writable.'
            );
        }

        return $path;
    }

    /** Remove a stored file. Silent when there is nothing to remove. */
    public function delete(?string $path): void
    {
        if ($this->named($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * The URL to print in an <img>, or null when there is no file to print.
     *
     * Null rather than a URL that 404s: every screen that shows a picture has a
     * placeholder for the product that has none, and that same placeholder is
     * the right answer for a product whose file has gone missing.
     */
    public function url(?string $path): ?string
    {
        if (!$this->exists($path)) {
            return null;
        }

        /*
         * Storage::url() and not asset('storage/...'): they agree on a normal
         * install, but a shared host that cannot make symlinks points the public
         * disk at a folder inside the web root instead (see the README), and
         * only the disk's own `url` config knows where that is.
         */
        $url = Storage::disk(self::DISK)->url($path);

        /*
         * ...but Storage::url() builds an ABSOLUTE url from APP_URL, while
         * asset() resolves against the host the browser actually asked for. An
         * install reached on a name APP_URL does not mention - a .test vhost, a
         * LAN address, a till on 192.168.x.y - then gets every image pointed at
         * the wrong host, and the browser shows a broken picture while the file
         * sits perfectly well on disk.
         *
         * So when the url is on this app's own configured root, hand back just
         * the path and let the browser resolve it. A url somewhere else entirely
         * (a CDN, or that shared-host folder) is left absolute, because that is
         * the case the disk config exists to express.
         */
        $root = rtrim((string) config('app.url'), '/');

        if ($root !== '' && str_starts_with($url, $root)) {
            return substr($url, strlen($root)) ?: '/';
        }

        return $url;
    }

    /** Is the file this path names actually on the disk? */
    public function exists(?string $path): bool
    {
        return $this->named($path) && Storage::disk(self::DISK)->fileExists($path);
    }

    /**
     * Create the folder uploads are written into.
     *
     * Flysystem would create it on the way past anyway. What this is for is the
     * root: `php artisan storage:link` does NOT create the directory it links
     * to, so on a deploy that copied no empty folders the link is made,
     * everything looks right, and every picture is a 404 until the first upload
     * happens to create the target. Doing it here means storing a picture also
     * repairs that.
     */
    public function ensureDirectory(string $folder = ''): void
    {
        Storage::disk(self::DISK)->makeDirectory($folder);
    }

    /**
     * Is the link that makes uploads reachable missing?
     *
     * This is the 403 a shop reported: the logo uploaded, the row saved, the
     * file was on the disk, and the browser got 403 on every picture because
     * `public/storage` did not exist. Nothing in PHP sees that request - the web
     * server answers it - so there is nothing in the log to explain it either,
     * which is why it is worth saying so on the screen that uploads pictures.
     *
     * Asked of config('filesystems.links') rather than of a hardcoded path, so
     * an install that serves the public disk from somewhere else is not nagged
     * about a link it does not use. file_exists() follows a symlink, so a link
     * pointing at a folder that is not there answers false as well - and that is
     * the same 403.
     */
    public function publicLinkMissing(): bool
    {
        $root = realpath((string) config('filesystems.disks.' . self::DISK . '.root'));

        if ($root === false) {
            // The disk's own folder is not there yet, so there is nothing to
            // serve and nothing useful to say about the link.
            return false;
        }

        foreach ((array) config('filesystems.links', []) as $link => $target) {
            if (realpath((string) $target) === $root && !file_exists($link)) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The name the file is stored under: forty random characters, plus an
     * extension decided by the content.
     *
     * None of the name the browser sent survives, which settles three problems
     * at once. It cannot be a path ("../../../.env"), it cannot collide (two
     * shops both uploading "logo.png" get two files), and it cannot smuggle a
     * second extension past the validator ("logo.png.php"). Taking the
     * extension from the bytes rather than the name means that even a file that
     * somehow got past the rules above cannot land on disk named as something
     * the web server would execute.
     *
     * @throws RuntimeException when the content is not one of the types we store
     */
    private function filename(UploadedFile $file): string
    {
        $guessed = Str::lower((string) $file->guessExtension());

        if (!isset(self::EXTENSIONS[$guessed])) {
            throw new RuntimeException('That file is not a JPEG, PNG or WebP picture.');
        }

        return Str::random(40) . '.' . self::EXTENSIONS[$guessed];
    }

    /** A path that is worth asking the disk about. */
    private function named(?string $path): bool
    {
        return is_string($path) && trim($path) !== '';
    }
}

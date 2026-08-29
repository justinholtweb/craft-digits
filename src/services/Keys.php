<?php

declare(strict_types=1);

namespace justinholtweb\digits\services;

use craft\base\Component;
use craft\db\Query;
use justinholtweb\digits\db\Table;
use justinholtweb\digits\Plugin;

/**
 * Making licence keys, and recognising them again afterwards.
 *
 * Two properties matter and neither is cryptographic secrecy — a key is a customer's copy of an
 * entitlement, not a password, and the activation API checks it against the database on every
 * call. What it has to be is **unguessable enough that nobody enumerates the space**, and
 * **transcribable by a person reading it down a telephone**.
 *
 * The second is why the default alphabet has no `I`, `O`, `0` or `1` in it, and why
 * {@see normalize()} is generous about what it accepts back: a customer who types `l` for `1`,
 * lower-cases the lot and leaves the dashes out should still get in. The first is why the
 * characters come from `random_int()` and not from `rand()`, `uniqid()`, or a hash of anything the
 * shop already knows.
 */
class Keys extends Component
{
    /** Attempts before giving up on finding an unused key. */
    private const MAX_ATTEMPTS = 12;

    /**
     * A key nothing else is using.
     *
     * The uniqueness check is a read followed by a write, so two requests could in principle pass
     * it at the same moment. They cannot both then be saved: the `licenseKey` column carries a
     * unique index, and the loser's insert fails rather than quietly issuing a duplicate. This
     * loop is what keeps that from happening in the first place; the index is what makes it safe
     * that it is only a loop.
     */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $key = $this->format();

            if (!$this->exists($key)) {
                return $key;
            }
        }

        // Every attempt collided, which on any sane alphabet means the format is too short rather
        // than that the shop got unlucky. Adding entropy is better than throwing at a customer who
        // has already paid.
        return $this->format() . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /** One key in the configured shape, with no uniqueness check. */
    public function format(?string $pattern = null, ?string $alphabet = null): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $pattern = $pattern ?? $settings->keyFormat;
        $alphabet = $alphabet ?? $settings->keyAlphabet;

        if (mb_strlen($alphabet) < 2) {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        }

        $length = mb_strlen($alphabet);
        $key = '';

        foreach (preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $key .= $char === 'X' ? mb_substr($alphabet, random_int(0, $length - 1), 1) : $char;
        }

        return $settings->keyPrefix . $key;
    }

    /**
     * The form of a key used to *find* one somebody typed in.
     *
     * Upper-cased, stripped of everything that is not a letter or a digit, and with the characters
     * the default alphabet leaves out folded on to the ones they are mistaken for: `l`, `I` and
     * `1` are the same keystroke to somebody copying from a printed invoice.
     */
    public function normalize(string $key): string
    {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key) ?? '');

        return strtr($key, [
            'I' => '1',
            'L' => '1',
            'O' => '0',
        ]);
    }

    /**
     * Whether a key is already in use.
     *
     * Compared against the stored key rather than the normalised one — normalising is for finding
     * a licence a human typed, and doing it here would make two genuinely different generated keys
     * look like a collision.
     */
    public function exists(string $key): bool
    {
        return (new Query())
            ->from([Table::LICENSES])
            ->where(['licenseKey' => $key])
            ->exists();
    }
}

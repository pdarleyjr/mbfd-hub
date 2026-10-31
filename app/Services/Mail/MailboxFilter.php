<?php

declare(strict_types=1);

namespace App\Services\Mail;

use InvalidArgumentException;

/** What a mailbox list is asked to show. Immutable; build variants with the with* methods. */
final class MailboxFilter
{
    public const INBOX = 'inbox';

    public const ASSIGNED = 'assigned';

    public const STARRED = 'starred';

    public const DRAFTS = 'drafts';

    public const SENT = 'sent';

    public const AUTOMATED = 'automated';

    public const ARCHIVE = 'archive';

    public const TRASH = 'trash';

    public const FOLDERS = [self::INBOX, self::ASSIGNED, self::STARRED, self::DRAFTS, self::SENT, self::AUTOMATED, self::ARCHIVE, self::TRASH];

    public const MAX_SEARCH_LENGTH = 100;

    public function __construct(
        public readonly string $folder = self::INBOX,
        public readonly ?string $search = null,
        public readonly ?string $label = null,
        public readonly bool $unreadOnly = false,
        public readonly bool $withAttachments = false,
        public readonly ?string $priority = null,
    ) {
        if (! in_array($folder, self::FOLDERS, true)) {
            throw new InvalidArgumentException('Unknown mail folder.');
        }
        if ($priority !== null && ! in_array($priority, ['normal', 'high'], true)) {
            throw new InvalidArgumentException('Unknown priority.');
        }
    }

    public static function folder(string $folder): self
    {
        return new self($folder);
    }

    public function withSearch(?string $search): self
    {
        $search = $search === null ? null : trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_substr($search, 0, self::MAX_SEARCH_LENGTH)));

        return new self($this->folder, $search === '' ? null : $search, $this->label, $this->unreadOnly, $this->withAttachments, $this->priority);
    }

    public function withLabel(?string $label): self
    {
        return new self($this->folder, $this->search, $label === null || trim($label) === '' ? null : trim($label), $this->unreadOnly, $this->withAttachments, $this->priority);
    }

    public function onlyUnread(bool $unreadOnly = true): self
    {
        return new self($this->folder, $this->search, $this->label, $unreadOnly, $this->withAttachments, $this->priority);
    }

    public function onlyWithAttachments(bool $with = true): self
    {
        return new self($this->folder, $this->search, $this->label, $this->unreadOnly, $with, $this->priority);
    }

    public function withPriority(?string $priority): self
    {
        return new self($this->folder, $this->search, $this->label, $this->unreadOnly, $this->withAttachments, $priority === null || $priority === '' ? null : $priority);
    }
}

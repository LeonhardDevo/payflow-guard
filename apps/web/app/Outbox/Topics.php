<?php

declare(strict_types=1);

namespace App\Outbox;

final class Topics
{
    public const string EMAIL_SEND = 'email.send';

    public const string TELEGRAM_NOTIFY = 'telegram.notify';

    public const string DOCUMENT_UPLOADED = 'document.uploaded';

    public const string DOCUMENT_DELETED = 'document.deleted';

    public const string WORKSPACE_REEMBED = 'workspace.reembed';

    public const string PRIVACY_EXPORT_REQUESTED = 'privacy.export.requested';

    public const string PRIVACY_PURGE_DUE = 'privacy.purge.due';

    public const string WORKSPACE_PURGE = 'workspace.purge';

    public const string USER_PURGE = 'user.purge';
}

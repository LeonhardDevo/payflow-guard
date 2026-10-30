<?php

declare(strict_types=1);

/*
| Audit action labels: `audit.actions.<action>` where <action> is e.g. `member.role_changed`.
*/

return [

    'actions' => [
        'auth' => [
            'login' => 'Вход в систему',
            'logout' => 'Выход из системы',
            'login_failed' => 'Неудачная попытка входа',
            'lockout' => 'Вход заблокирован после множества попыток',
            'registered' => 'Создан аккаунт',
            'email_verified' => 'Почта подтверждена',
            'password_reset' => 'Пароль сброшен',
            'two_factor_enabled' => 'Включена двухфакторная аутентификация',
            'account_deleted' => 'Аккаунт удалён',
            'two_factor_disabled' => 'Отключена двухфакторная аутентификация',
        ],
        'workspace' => [
            'created' => 'Создано пространство',
            'updated' => 'Изменены настройки пространства',
            'deleted' => 'Пространство удалено',
            'deletion_scheduled' => 'Запланировано удаление пространства',
            'deletion_cancelled' => 'Удаление пространства отменено',
        ],
        'member' => [
            'role_changed' => 'Изменена роль участника',
            'removed' => 'Участник удалён',
            'left' => 'Участник покинул пространство',
        ],
        'invitation' => [
            'created' => 'Отправлено приглашение',
            'revoked' => 'Приглашение отозвано',
            'accepted' => 'Приглашение принято',
        ],
        'billing' => [
            'subscription_changed' => 'Изменена подписка',
        ],
        'ai' => [
            'settings_updated' => 'Изменены настройки ИИ',
            'key_updated' => 'Сохранён ключ провайдера ИИ',
            'key_cleared' => 'Удалён ключ провайдера ИИ',
        ],
        'document' => [
            'uploaded' => 'Загружен документ',
            'updated' => 'Изменены данные документа',
            'deleted' => 'Документ удалён',
        ],
        'telegram' => [
            'linked' => 'Подключён Telegram',
            'unlinked' => 'Отключён Telegram',
        ],
        'assistant' => [
            'escalated' => 'Вопрос передан администраторам',
        ],
        'legal' => [
            'accepted' => 'Принят юридический документ',
        ],
        'privacy' => [
            'export_requested' => 'Запрошена выгрузка персональных данных',
            'workspace_export_requested' => 'Запрошена выгрузка рабочего пространства',
            'export_ready' => 'Выгрузка данных готова',
            'export_failed' => 'Ошибка выгрузки данных',
            'export_downloaded' => 'Выгрузка данных скачана',
            'deletion_scheduled' => 'Запланировано удаление аккаунта',
            'deletion_cancelled' => 'Удаление аккаунта отменено',
            'deletion_completed' => 'Аккаунт удалён безвозвратно',
            'workspace_purged' => 'Данные рабочего пространства удалены',
            'settings_updated' => 'Изменены настройки конфиденциальности',
            'retention_purged' => 'Выполнена очистка по срокам хранения',
        ],
        'email' => [
            'opted_out' => 'Получатель отписался от автоматических писем',
        ],
    ],

    'system' => 'Система',

];

<?php

// Russian strings missing from Filament's own ru files. Merged over them by Laravel.

return [
    'column_manager' => [
        'position' => 'Позиция :position из :count.',
        'position_in_group' => 'Позиция :position из :count в группе :group.',
        'reordered' => 'Столбец :column перемещён на позицию :position из :count.',
        'reordered_deferred' => 'Столбец :column перемещён на позицию :position из :count. Примените столбцы, чтобы сохранить изменения.',
        'reordered_in_group' => 'Столбец :column перемещён на позицию :position из :count в группе :group.',
        'reordered_in_group_deferred' => 'Столбец :column перемещён на позицию :position из :count в группе :group. Примените столбцы, чтобы сохранить изменения.',
        'actions' => [
            'move_up' => ['label' => 'Переместить :column выше'],
            'move_down' => ['label' => 'Переместить :column ниже'],
            'reorder' => ['label' => 'Изменить порядок столбца'],
        ],
    ],
    'columns' => [
        'editable' => [
            'saving' => 'Сохранение…',
            'saved' => 'Изменение сохранено.',
            'failure' => 'Не удалось подтвердить сохранение. Обновите страницу и попробуйте снова.',
            'unavailable' => 'Этот элемент больше нельзя изменить.',
        ],
        'icon' => [
            'boolean' => [
                'true' => 'Да',
                'false' => 'Нет',
            ],
        ],
    ],
    'actions' => [
        'reorder_record' => ['label' => 'Изменить порядок элемента :key'],
        'move_record_up' => ['label' => 'Переместить :label выше'],
        'move_record_down' => ['label' => 'Переместить :label ниже'],
        'toggle_record_content' => ['label' => 'Развернуть/свернуть элемент :key'],
    ],
    'loading' => 'Загрузка…',
    'reordering' => [
        'position' => ':label, позиция :position из :count.',
        'moved' => ':label перемещён на позицию :position из :count.',
    ],
    'result_count' => '{0} Нет результатов|[1,*] Результатов: :count',
];

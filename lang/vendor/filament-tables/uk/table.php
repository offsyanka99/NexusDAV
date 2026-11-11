<?php

// Ukrainian strings missing from Filament's own uk files. Merged over them by Laravel.
return [
    'column_manager' => [
        'position' => 'Позиція :position з :count.',
        'position_in_group' => 'Позиція :position з :count у групі :group.',
        'reordered' => 'Стовпець :column переміщено на позицію :position з :count.',
        'reordered_deferred' => 'Стовпець :column переміщено на позицію :position з :count. Застосуйте стовпці, щоб зберегти зміни.',
        'reordered_in_group' => 'Стовпець :column переміщено на позицію :position з :count у групі :group.',
        'reordered_in_group_deferred' => 'Стовпець :column переміщено на позицію :position з :count у групі :group. Застосуйте стовпці, щоб зберегти зміни.',
        'actions' => [
            'move_up' => ['label' => 'Перемістити :column вище'],
            'move_down' => ['label' => 'Перемістити :column нижче'],
            'reorder' => ['label' => 'Змінити порядок стовпця'],
        ],
    ],
    'columns' => [
        'editable' => [
            'saving' => 'Збереження…',
            'saved' => 'Зміну збережено.',
            'failure' => 'Не вдалося підтвердити збереження. Оновіть сторінку й спробуйте ще раз.',
            'unavailable' => 'Цей елемент більше не можна змінити.',
        ],
        'icon' => [
            'boolean' => [
                'true' => 'Так',
                'false' => 'Ні',
            ],
        ],
    ],
    'actions' => [
        'reorder_record' => ['label' => 'Змінити порядок елемента :key'],
        'move_record_up' => ['label' => 'Перемістити :label вище'],
        'move_record_down' => ['label' => 'Перемістити :label нижче'],
        'toggle_record_content' => ['label' => 'Розгорнути/згорнути елемент :key'],
    ],
    'loading' => 'Завантаження…',
    'reordering' => [
        'position' => ':label, позиція :position з :count.',
        'moved' => ':label переміщено на позицію :position з :count.',
    ],
    'result_count' => '{0} Немає результатів|[1,*] Результатів: :count',
];

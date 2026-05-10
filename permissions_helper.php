<?php
function defaultUserPermissions(): array
{
    return [
        'can_view_members'        => 1,
        'can_view_renew_members'  => 1,
        'can_view_attendance'     => 1,
        'can_view_expenses'       => 1,
        'can_view_stats'          => 1,
        'can_view_settings'       => 1,
        'can_view_closing'        => 1,
    ];
}

function loadUserPermissions(PDO $pdo, string $role, int $userId): array
{
    $perms = defaultUserPermissions();

    if ($role !== 'مشرف' || $userId <= 0) {
        return $perms;
    }

    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);

        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            foreach ($perms as $key => $value) {
                if (isset($rowPerm[$key])) {
                    $perms[$key] = (int)$rowPerm[$key];
                }
            }
        }
    } catch (Exception $e) {
        // نحتفظ بالقيم الافتراضية حتى لا تتعطل الصفحات إذا تعذر تحميل الصلاحيات.
    }

    return $perms;
}
?>

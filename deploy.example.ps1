# deploy.example.ps1 - 家族カレンダーを aitakt.gardenas.net/family/ に置く
# deploy.ps1 という名前でコピーして $TOKEN に BackYard のデプロイ用トークンを入れる（deploy.ps1 はコミットしない）
# BackYard の受け取り口（api/deploy_receiver.php）が「aitakt/」で始まるパスを aitakt のフォルダに書き込む
# 使い方: .\deploy.ps1                       … family フォルダの全部（config.local.php も含む）
#         .\deploy.ps1 -Files "family/tv.js"  … 指定したものだけ（複数は "family/a.php","family/b.js"）

param(
    [string[]]$Files = @()
)

$TOKEN    = 'change-me'
$BASE_URL = 'https://backyard.gardenas.net/api/deploy_receiver.php'
$LOCAL    = Split-Path $MyInvocation.MyCommand.Path

if ($Files.Count -gt 0) {
    $targets = $Files
} else {
    $targets = Get-ChildItem (Join-Path $LOCAL 'family') -Recurse -File |
        ForEach-Object { $_.FullName.Substring($LOCAL.Length + 1) }
}

$ok = 0
$fail = 0
foreach ($rel in $targets) {
    $rel = $rel -replace '\\', '/'
    $local_path = Join-Path $LOCAL ($rel -replace '/', '\')
    if (-not (Test-Path $local_path)) {
        Write-Host "  SKIP  $rel" -ForegroundColor Yellow
        continue
    }

    $bytes = [IO.File]::ReadAllBytes($local_path)
    $b64   = [Convert]::ToBase64String($bytes)

    try {
        $res = Invoke-RestMethod `
            -Uri ($BASE_URL + '?token=' + $TOKEN) `
            -Method POST `
            -Body @{ path = ('aitakt/' + $rel); content = $b64 } `
            -TimeoutSec 30
        Write-Host "  OK    $rel  ($($res.bytes) bytes)" -ForegroundColor Green
        $ok++
    } catch {
        Write-Host "  FAIL  $rel  $($_.Exception.Message)" -ForegroundColor Red
        $fail++
    }
}

Write-Host ""
Write-Host "Done: $ok ok / $fail failed" -ForegroundColor Cyan

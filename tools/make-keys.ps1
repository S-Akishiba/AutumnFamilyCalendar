# make-keys.ps1 - family/config.local.php に貼る値を作る（パスワードの暗号化・秘密の文字列・テレビの鍵）
# 使い方: .\tools\make-keys.ps1

$pw = Read-Host '家族のパスワード（iPhone でログインするときに入れるもの）'
$sha = [Security.Cryptography.SHA256]::Create()
$hash = -join ($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($pw)) | ForEach-Object { $_.ToString('x2') })

function New-RandomHex([int]$bytes) {
    $buf = New-Object byte[] $bytes
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    -join ($buf | ForEach-Object { $_.ToString('x2') })
}

Write-Host ''
Write-Host 'family\config.local.php の該当の行をこれに置き換える:' -ForegroundColor Cyan
Write-Host "define('FAMILY_PASSWORD_HASH', 'sha256:$hash');"
Write-Host "define('FAMILY_SECRET', '$(New-RandomHex 32)');"
Write-Host "define('FAMILY_TV_KEY', '$(New-RandomHex 16)');"

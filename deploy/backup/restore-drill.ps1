<#
.SYNOPSIS
  DavetKart geri yukleme provasi: bir dokumu bos bir prova veritabanina acar,
  satir sayilarini manifest.json ile karsilastirir ve sureyi yazar.
  Ayrinti: docs/rehber/deploy/backup/restore-drill.md

.EXAMPLE
  # Tam prova (dosyalar konsoldan indirilmis; manifest ayni klasorde aranir)
  powershell -ExecutionPolicy Bypass -File deploy\backup\restore-drill.ps1 -Dump C:\Yedek\davetkart-20261117T020003Z.dump

.EXAMPLE
  # Senaryo A: yalnizca 'orders' tablosunu yan veritabanina ac
  powershell -ExecutionPolicy Bypass -File deploy\backup\restore-drill.ps1 -Dump C:\Yedek\davetkart-20261117T020003Z.dump -OnlyTable orders

.NOTES
  Parola parametre DEGILDIR: $env:PGPASSWORD ya da %APPDATA%\postgresql\pgpass.conf kullanilir.
  Windows PowerShell 5.1 ile uyumludur. Canli veritabanina dokunmaz.
#>
[CmdletBinding()]
param(
    [string]$Dump,
    [string]$Manifest,
    [string]$S3Uri,
    [string]$AwsProfile,
    [string]$DownloadDir,
    [string]$Database,
    [string]$OnlyTable,
    [string]$PgBin,
    [string]$PgHost = '127.0.0.1',
    [int]$PgPort = 5432,
    [string]$PgUser = 'postgres'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Step([string]$Text) { Write-Host "==> $Text" }
function Stop-Drill([string]$Text) { Write-Host "HATA: $Text"; exit 1 }

# Yerel araclar stderr'e yazar; PS 5.1'de bu 'Stop' ile betigi keser. Ciktiyi metne cevirip cikis kodunu dondur.
function Invoke-Native([string]$Exe, [string[]]$Arguments) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $lines = & $Exe @Arguments 2>&1 | ForEach-Object { "$_" }
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    return [pscustomobject]@{ ExitCode = $code; Output = @($lines) }
}

function Resolve-PgTool([string]$Name) {
    if ($PgBin) {
        $candidate = Join-Path $PgBin "$Name.exe"
        if (Test-Path -LiteralPath $candidate) { return $candidate }
        Stop-Drill "$candidate yok (-PgBin yanlis mi?)"
    }
    $command = Get-Command $Name -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    $root = Join-Path $env:ProgramFiles 'PostgreSQL'
    if (Test-Path -LiteralPath $root) {
        $versions = Get-ChildItem -LiteralPath $root -Directory |
            Where-Object { $_.Name -match '^\d+$' } |
            Sort-Object { [int]$_.Name } -Descending
        foreach ($version in $versions) {
            $candidate = Join-Path $version.FullName "bin\$Name.exe"
            if (Test-Path -LiteralPath $candidate) { return $candidate }
        }
    }
    Stop-Drill "$Name bulunamadi. -PgBin ile PostgreSQL 'bin' klasorunu ver."
}

function Get-VersionParts([string]$Text) {
    if ($Text -match '(\d+)\.(\d+)') { return @([int]$Matches[1], [int]$Matches[2]) }
    Stop-Drill "surum okunamadi: $Text"
}

$drillWatch = [System.Diagnostics.Stopwatch]::StartNew()

# --- 0. Hedef veritabani: adinda '_restore_' gecmeyen hicbir veritabani silinmez -------------
if (-not $Database) {
    if ($OnlyTable) { $Database = 'davetkart_restore_side' } else { $Database = 'davetkart_restore_drill' }
}
if ($Database -notmatch '^[a-z][a-z0-9_]*$' -or $Database -notmatch '_restore_') {
    Stop-Drill "prova veritabaninin adinda '_restore_' gecmeli (verilen: $Database). Canli ya da gelistirme veritabani hedef olamaz."
}
if ($OnlyTable -and $OnlyTable -notmatch '^[a-z_][a-z0-9_]*$') {
    Stop-Drill "gecersiz tablo adi: $OnlyTable"
}

$pgRestore = Resolve-PgTool 'pg_restore'
$psql = Resolve-PgTool 'psql'
$createdb = Resolve-PgTool 'createdb'
$dropdb = Resolve-PgTool 'dropdb'
$conn = @('-h', $PgHost, '-p', "$PgPort", '-U', $PgUser, '-w')

# --- 1. Dosyalar: S3'ten indir ya da verilen yolu kullan -------------------------------------
if ($S3Uri) {
    if ($S3Uri -notmatch '^s3://.+\.dump$') { Stop-Drill "-S3Uri bir .dump nesnesini gostermeli: s3://<bucket>/daily/YYYY/MM/DD/<ad>.dump" }
    $aws = Get-Command aws -ErrorAction SilentlyContinue
    if (-not $aws) { Stop-Drill "aws CLI yok. Iki dosyayi konsoldan indir ve -Dump ile ver." }
    if (-not $DownloadDir) { $DownloadDir = Join-Path $env:TEMP 'davetkart-restore-drill' }
    New-Item -ItemType Directory -Force -Path $DownloadDir | Out-Null
    $Dump = Join-Path $DownloadDir (Split-Path $S3Uri -Leaf)
    $Manifest = $Dump -replace '\.dump$', '.manifest.json'
    $pairs = @(@($S3Uri, $Dump), @(($S3Uri -replace '\.dump$', '.manifest.json'), $Manifest))
    foreach ($pair in $pairs) {
        Write-Step "S3'ten indiriliyor: $($pair[0])"
        $awsArgs = @('s3', 'cp', $pair[0], $pair[1], '--only-show-errors')
        if ($AwsProfile) { $awsArgs += @('--profile', $AwsProfile) }
        $result = Invoke-Native $aws.Source $awsArgs
        if ($result.ExitCode -ne 0) { $result.Output | Write-Host; Stop-Drill "indirme basarisiz" }
    }
}
if (-not $Dump) { Stop-Drill "-Dump <dosya> ya da -S3Uri <s3://...dump> ver." }
if (-not (Test-Path -LiteralPath $Dump)) { Stop-Drill "dokum dosyasi yok: $Dump" }
$Dump = (Resolve-Path -LiteralPath $Dump).Path
if (-not $Manifest) {
    if ($Dump -notmatch '\.dump$') { Stop-Drill "dokum adi .dump ile bitmiyor; manifest yolunu -Manifest ile ver." }
    $Manifest = $Dump -replace '\.dump$', '.manifest.json'
}
if (-not (Test-Path -LiteralPath $Manifest)) { Stop-Drill "manifest yok: $Manifest (dokumle ayni klasore indir ya da -Manifest ver)" }

# --- 2. Manifest ve dosya butunlugu -----------------------------------------------------------
Write-Step "Manifest okunuyor: $Manifest"
try {
    $m = Get-Content -LiteralPath $Manifest -Raw | ConvertFrom-Json
} catch {
    Stop-Drill "manifest gecerli bir JSON degil: $Manifest"
}
if (-not ($m.PSObject.Properties.Name -contains 'schema') -or $m.schema -ne 1) { Stop-Drill "bilinmeyen manifest semasi" }
$expected = [ordered]@{}
foreach ($property in $m.tables.PSObject.Properties) {
    if ($property.Name -notmatch '^[a-z_][a-z0-9_]*$') { Stop-Drill "manifestte gecersiz tablo adi: $($property.Name)" }
    $expected[$property.Name] = [long]$property.Value
}
if ($OnlyTable -and -not $expected.Contains($OnlyTable)) { Stop-Drill "manifestte '$OnlyTable' tablosu yok" }

Write-Step "Dosya butunlugu (boyut + SHA-256)"
$actualBytes = (Get-Item -LiteralPath $Dump).Length
$actualHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $Dump).Hash.ToLower()
if ($actualBytes -ne [long]$m.dump.bytes) { Stop-Drill "boyut farkli: dosya $actualBytes, manifest $($m.dump.bytes)" }
if ($actualHash -ne ([string]$m.dump.sha256).ToLower()) { Stop-Drill "SHA-256 farkli: dosya bozuk ya da manifest baska bir dokume ait" }

# --- 3. Surum uyumu: pg_restore ve hedef sunucu, dokumu alan ana surumden eski olamaz ---------
$restoreVersion = Get-VersionParts ((Invoke-Native $pgRestore @('--version')).Output[0])
$dumpVersion = Get-VersionParts ([string]$m.pgDumpVersion)
$serverResult = Invoke-Native $psql ($conn + @('-X', '-At', '-d', 'postgres', '-c', 'show server_version'))
if ($serverResult.ExitCode -ne 0) {
    $serverResult.Output | Write-Host
    Stop-Drill "yerel PostgreSQL'e baglanilamadi. Parola icin `$env:PGPASSWORD ya da pgpass.conf kullan."
}
$targetVersion = Get-VersionParts $serverResult.Output[0]
Write-Host ("    dokum: pg_dump {0}.{1} | yerel pg_restore {2}.{3} | hedef sunucu {4}.{5}" -f `
    $dumpVersion[0], $dumpVersion[1], $restoreVersion[0], $restoreVersion[1], $targetVersion[0], $targetVersion[1])
if ($restoreVersion[0] -lt $dumpVersion[0] -or $targetVersion[0] -lt $dumpVersion[0]) {
    Stop-Drill "yerel PostgreSQL ana surumu dokumunkinden eski: PostgreSQL $($dumpVersion[0]) kur."
}
if ($restoreVersion[0] -eq $dumpVersion[0] -and $restoreVersion[1] -lt $dumpVersion[1]) {
    Write-Host "    UYARI: yerel kucuk surum dokumunkinden eski; hata alirsan yerel PostgreSQL'i guncelle."
}

# --- 4. Bos prova veritabani ------------------------------------------------------------------
Write-Step "Prova veritabani yeniden olusturuluyor: $Database"
$result = Invoke-Native $dropdb ($conn + @('--if-exists', $Database))
if ($result.ExitCode -ne 0) { $result.Output | Write-Host; Stop-Drill "dropdb basarisiz" }
$result = Invoke-Native $createdb ($conn + @($Database))
if ($result.ExitCode -ne 0) { $result.Output | Write-Host; Stop-Drill "createdb basarisiz" }

# --- 5. Geri yukleme (sure olculur) -----------------------------------------------------------
$restoreArgs = $conn + @('--no-owner', '--no-acl', '-d', $Database)
if ($OnlyTable) {
    Write-Step "Tek tablo geri yukleniyor: $OnlyTable (indeks ve kisitlar gelmez)"
    $restoreArgs += @('-t', $OnlyTable)
} else {
    Write-Step "Geri yukleniyor (pg_restore --no-owner --no-acl)"
}
$restoreWatch = [System.Diagnostics.Stopwatch]::StartNew()
$restore = Invoke-Native $pgRestore ($restoreArgs + @($Dump))
$restoreWatch.Stop()
$restoreFailed = ($restore.ExitCode -ne 0)
if ($restoreFailed) {
    $restore.Output | Write-Host
    Write-Host "HATA: pg_restore cikis kodu $($restore.ExitCode)"
}

# --- 6. Sayilari karsilastir ------------------------------------------------------------------
Write-Step "Satir sayilari karsilastiriliyor"
$listResult = Invoke-Native $psql ($conn + @('-X', '-At', '-d', $Database, '-c',
    "select tablename from pg_tables where schemaname = 'public' order by 1"))
if ($listResult.ExitCode -ne 0) { $listResult.Output | Write-Host; Stop-Drill "tablo listesi alinamadi" }
$restoredTables = @($listResult.Output | Where-Object { $_ -match '^[a-z_][a-z0-9_]*$' })

$actual = @{}
if ($restoredTables.Count -gt 0) {
    $parts = $restoredTables | ForEach-Object { "select '$_', count(*) from public.$_" }
    $countResult = Invoke-Native $psql ($conn + @('-X', '-At', '-d', $Database, '-c', ($parts -join ' union all ')))
    if ($countResult.ExitCode -ne 0) { $countResult.Output | Write-Host; Stop-Drill "satir sayilari alinamadi" }
    foreach ($line in $countResult.Output) {
        $fields = $line -split '\|'
        if ($fields.Count -eq 2) { $actual[$fields[0]] = [long]$fields[1] }
    }
}

if ($OnlyTable) { $names = @($OnlyTable) } else { $names = @($expected.Keys) + @($restoredTables | Where-Object { -not $expected.Contains($_) }) }
$rows = @()
$different = 0
$rowTotal = 0
foreach ($name in $names) {
    $want = $null
    $have = $null
    if ($expected.Contains($name)) { $want = $expected[$name] }
    if ($actual.ContainsKey($name)) { $have = $actual[$name]; $rowTotal += $have }
    if ($null -eq $have) { $state = 'EKSIK' }
    elseif ($null -eq $want) { $state = 'FAZLA' }
    elseif ($want -eq $have) { $state = 'ok' }
    else { $state = 'FARK' }
    if ($state -ne 'ok') { $different++ }
    $rows += [pscustomobject]@{ Tablo = $name; Manifest = $want; GeriYuklenen = $have; Durum = $state }
}
($rows | Format-Table -AutoSize | Out-String).TrimEnd() | Write-Host

# --- 7. Sonuc ---------------------------------------------------------------------------------
$drillWatch.Stop()
$megabytes = [math]::Round($actualBytes / 1MB, 2)
Write-Host ""
Write-Host ("Dokum            : {0} ({1} MB, alinma {2}, son goc {3})" -f (Split-Path $Dump -Leaf), $megabytes, $m.createdAt, $m.lastMigration)
Write-Host ("Geri yukleme     : {0:N1} sn ({1} tablo, {2} satir)" -f $restoreWatch.Elapsed.TotalSeconds, $rows.Count, $rowTotal)
Write-Host ("Provanin tamami  : {0:N1} sn (indirme ve karar suresi haric)" -f $drillWatch.Elapsed.TotalSeconds)
Write-Host ("Prova veritabani : {0} (birakildi). Silmek icin:" -f $Database)
Write-Host ("    & '{0}' -h {1} -p {2} -U {3} {4}" -f $dropdb, $PgHost, $PgPort, $PgUser, $Database)

if ($restoreFailed -or $different -gt 0) {
    Write-Host ""
    Write-Host "SONUC: BASARISIZ (pg_restore hatasi: $restoreFailed, farkli tablo: $different)"
    exit 1
}
Write-Host ""
if ($OnlyTable) {
    Write-Host "SONUC: '$OnlyTable' yan veritabaninda ($Database), satir sayisi manifestle ayni."
    Write-Host "Sonraki adim ELLE: satirlari canli veriyle karsilastir (rehber: restore-drill.md, 'Senaryo A')."
} else {
    Write-Host "SONUC: BASARILI. Geri yukleme suresini YEDEK-VE-GERI-YUKLEME.md prova kaydina yaz."
}
exit 0

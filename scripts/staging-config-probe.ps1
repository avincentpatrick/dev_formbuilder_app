# Staging Apache configuration probe (M251, R-a3918bf4) - the FETCH half. READ-ONLY.
#
# WHY THIS EXISTS. Everything this repository knows about the testing box's Apache configuration was
# prose in docs/deployment-infrastructure.md section 8.3, checked by nothing: which vhost carries the
# X-Robots-Tag line, the three certificate traps, the <MDomain>, the port-80 redirect. Two rows once sat
# open for an increment each because the only instrument was a human pasting console output. This
# script prints what the box actually has, and scripts/staging-config-judge.php RULES on that output:
# the judge is the half that can go red, and tests/Feature/Docs/StagingConfigJudgeTest.php is its floor.
#
# IT CHANGES NOTHING. It reads files, hashes them and runs `httpd -S`, which parses the configuration
# without starting a server. tests/Feature/Docs/StagingConfigJudgeTest.php refuses any cmdlet here that
# writes, moves, stops or restarts something. Run it whenever Apache's configuration has been edited.
#
# HOW TO RUN IT, in an administrator PowerShell on the box, then copy the whole Notepad window into the session:
#   powershell -NoProfile -ExecutionPolicy Bypass -File C:\meridian\test-app\scripts\staging-config-probe.ps1 |
#       Out-File -FilePath C:\meridian\config-probe.txt -Encoding utf8; notepad C:\meridian\config-probe.txt
# Before this file is on the box, save it from Notepad as C:\meridian\config-probe.ps1 and run that copy.
# Through a file, never `| Set-Clipboard` and never console text: on 2026-11-24 the clipboard route returned
# nothing at all, and a console that wraps a long line breaks a record in two, which the judge refuses as
# not measured (exit 2) rather than guess.
#
# OUTPUT. One record per line, fields separated by `|`:
#   meridian-config-probe|v2|<UTC time>      the signature the judge recognises; nothing before it counts
#   root|<Apache folder>
#   file|<path>|<sha256>|<lines>             each live file: httpd.conf and every file an Include names
#   d|<path>|<line>|<directive>              each directive the judge reads, and EVERY section line
#                                            (<VirtualHost>, <IfModule>, <Location>, their closing tags)
#   x|<path>|<line>                          an X-Robots-Tag line in a file no Include names (inert)
#   s|<line of httpd -S>                     the server's own vhost map
#   end|<number of records above>            so a truncated paste is caught
# MDContactEmail's value is printed as <redacted>: the judge's fixtures live in a public repository (D48).
#
# WHY EVERY SECTION LINE. Without them a `Header` inside a <Location> reads exactly like one that covers
# the whole vhost, and the judge would pass a crawl header that guards one path. v1 printed only the
# <VirtualHost> and <MDomain> lines; it ran once on the box (2026-11-24) and the judge refuses it.

param(
    [string] $ApacheRoot = 'C:\Apache24'
)

$ErrorActionPreference = 'Continue'
$records = [System.Collections.Generic.List[string]]::new()
$root = (Resolve-Path -LiteralPath $ApacheRoot).Path.TrimEnd('\')
$wanted = @(
    'include', 'includeoptional', 'serverroot', 'listen', 'servername', 'serveralias',
    'header', 'redirect', 'redirectpermanent', 'redirectmatch',
    'mdcontactemail', 'mdcertificateagreement', 'mdcachallenges', 'mdprivatekeys', 'mdcertificatefile',
    'mdcertificatekeyfile', 'mdmembers', 'mdrenewmode', 'mdomain',
    'sslengine', 'sslcertificatefile', 'sslcertificatekeyfile', 'sslcertificatechainfile'
)

function Get-ProbeRelative([string] $path) {
    $full = [System.IO.Path]::GetFullPath($path)
    if ($full.StartsWith($root + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
        $full = $full.Substring($root.Length + 1)
    }

    return $full.Replace('\', '/')
}

$records.Add('meridian-config-probe|v2|' + [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ'))
$records.Add('root|' + $root.Replace('\', '/'))

# Walk httpd.conf and every file an Include names, depth first, each file once.
$defines = @{ 'SRVROOT' = $root.Replace('\', '/') }
$live = [System.Collections.Generic.List[string]]::new()
$queue = [System.Collections.Generic.Queue[string]]::new()
$queue.Enqueue((Join-Path $root 'conf\httpd.conf'))

while ($queue.Count -gt 0) {
    $file = [System.IO.Path]::GetFullPath($queue.Dequeue())
    if ($live -contains $file) { continue }
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        $records.Add('missing|' + (Get-ProbeRelative $file))
        continue
    }

    $live.Add($file)
    $relative = Get-ProbeRelative $file
    $lines = @(Get-Content -LiteralPath $file)
    $hash = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant()
    $records.Add('file|' + $relative + '|' + $hash + '|' + $lines.Count)

    for ($i = 0; $i -lt $lines.Count; $i++) {
        $text = ($lines[$i] -replace '\s+', ' ').Trim()
        if ($text -eq '' -or $text.StartsWith('#')) { continue }

        $first = $text.Split(' ')[0].ToLowerInvariant().TrimEnd('>')
        if ($first -eq 'define') {
            $parts = $text.Split(' ', 3)
            if ($parts.Count -ge 3) { $defines[$parts[1]] = $parts[2].Trim('"') }
        }

        $key = $text.Split(' ')[0].ToLowerInvariant()
        if ($wanted -notcontains $key -and -not $key.StartsWith('<')) { continue }

        if ($key -eq 'mdcontactemail') { $text = 'MDContactEmail <redacted>' }
        $records.Add('d|' + $relative + '|' + ($i + 1) + '|' + $text)

        if ($key -eq 'include' -or $key -eq 'includeoptional') {
            $target = $text.Split(' ', 2)[1].Trim().Trim('"')
            foreach ($name in $defines.Keys) { $target = $target.Replace('${' + $name + '}', $defines[$name]) }
            if (-not [System.IO.Path]::IsPathRooted($target)) { $target = Join-Path $root $target }
            if ($target.Contains('*') -or $target.Contains('?')) {
                foreach ($match in @(Get-ChildItem -Path $target -File -ErrorAction SilentlyContinue)) {
                    $queue.Enqueue($match.FullName)
                }
            } else {
                $queue.Enqueue($target)
            }
        }
    }
}

# Every X-Robots-Tag line in the conf tree that sits in a file no Include names: a backup, a copy.
foreach ($item in @(Get-ChildItem -LiteralPath (Join-Path $root 'conf') -Recurse -File -ErrorAction SilentlyContinue)) {
    if ($live -contains $item.FullName) { continue }
    $hits = @(Select-String -LiteralPath $item.FullName -Pattern 'X-Robots-Tag' -SimpleMatch -ErrorAction SilentlyContinue)
    foreach ($hit in $hits) {
        $records.Add('x|' + (Get-ProbeRelative $item.FullName) + '|' + $hit.LineNumber)
    }
}

# The server's own parse of which vhost answers which name, from the same configuration.
$httpd = Join-Path $root 'bin\httpd.exe'
if (Test-Path -LiteralPath $httpd -PathType Leaf) {
    foreach ($line in @(& $httpd -S 2>&1 | ForEach-Object { "$_" })) {
        $text = ($line -replace '\s+', ' ').Trim()
        if ($text -match '\.conf:\d+\)' -or $text -match '^\*:\d+ ') { $records.Add('s|' + $text) }
    }
} else {
    $records.Add('s|httpd.exe not found under ' + $root.Replace('\', '/'))
}

$count = $records.Count
$records.Add('end|' + $count)
$records

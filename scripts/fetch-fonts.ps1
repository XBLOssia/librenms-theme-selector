# Fetch the bundled webfonts from Google Fonts.
#
# End users do NOT need this - the .woff2 files are committed to the repo so a
# skin needs nothing downloaded at install time. This script exists to regenerate them
# reproducibly, and to document exactly where they came from.
#
# Only the "latin" unicode-range subset is taken. Google serves these already
# subsetted, so no fonttools/pyftsubset step is needed and each file lands at
# roughly 15-35KB instead of several hundred.
#
# All faces are SIL Open Font License 1.1, which permits redistribution.
#
#   powershell -ExecutionPolicy Bypass -File scripts/fetch-fonts.ps1

param([string[]]$Skins)  # only these skins' fonts (default: all), e.g. -Skins clock-tower-daylight,clock-tower-lantern

$ErrorActionPreference = 'Stop'
if ($Skins) { $Skins = @($Skins | ForEach-Object { $_ -split ',' }) }  # powershell -File passes a,b as one string
$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36'
$root = Split-Path -Parent $PSScriptRoot

# family, weight, destination skin, output filename
$faces = @(
  @{ family='Saira+Condensed'; name='Saira Condensed'; weight=600; skin='terran';  file='SairaCondensed-SemiBold.woff2' },
  @{ family='Saira+Condensed'; name='Saira Condensed'; weight=700; skin='terran';  file='SairaCondensed-Bold.woff2' },
  @{ family='JetBrains+Mono';  name='JetBrains Mono';  weight=400; skin='terran';  file='JetBrainsMono-Regular.woff2' },
  @{ family='JetBrains+Mono';  name='JetBrains Mono';  weight=700; skin='terran';  file='JetBrainsMono-Bold.woff2' },
  @{ family='Cinzel';          name='Cinzel';          weight=600; skin='protoss'; file='Cinzel-SemiBold.woff2' },
  @{ family='Rajdhani';        name='Rajdhani';        weight=500; skin='protoss'; file='Rajdhani-Medium.woff2' },
  @{ family='Rajdhani';        name='Rajdhani';        weight=700; skin='protoss'; file='Rajdhani-Bold.woff2' },
  @{ family='Space+Mono';      name='Space Mono';      weight=400; skin='protoss'; file='SpaceMono-Regular.woff2' },
  @{ family='Metamorphous';    name='Metamorphous';    weight=400; skin='zerg';    file='Metamorphous-Regular.woff2' },
  @{ family='Chakra+Petch';    name='Chakra Petch';    weight=500; skin='zerg';    file='ChakraPetch-Medium.woff2' },
  @{ family='Chakra+Petch';    name='Chakra Petch';    weight=700; skin='zerg';    file='ChakraPetch-Bold.woff2' },
  @{ family='Space+Mono';      name='Space Mono';      weight=400; skin='zerg';    file='SpaceMono-Regular.woff2' },
  @{ family='Playfair+Display';   name='Playfair Display';   weight=700; skin='clock-tower-daylight'; file='PlayfairDisplay-Bold.woff2' },
  @{ family='Libre+Baskerville';  name='Libre Baskerville';  weight=400; skin='clock-tower-daylight'; file='LibreBaskerville-Regular.woff2' },
  @{ family='Libre+Baskerville';  name='Libre Baskerville';  weight=700; skin='clock-tower-daylight'; file='LibreBaskerville-Bold.woff2' },
  @{ family='Playfair+Display';   name='Playfair Display';   weight=700; skin='clock-tower-lantern'; file='PlayfairDisplay-Bold.woff2' },
  @{ family='Libre+Baskerville';  name='Libre Baskerville';  weight=400; skin='clock-tower-lantern'; file='LibreBaskerville-Regular.woff2' },
  @{ family='Libre+Baskerville';  name='Libre Baskerville';  weight=700; skin='clock-tower-lantern'; file='LibreBaskerville-Bold.woff2' }
)

foreach ($f in $faces) {
  if ($Skins -and ($Skins -notcontains $f.skin)) { continue }
  $dest = Join-Path $root "skins\$($f.skin)\fonts"
  if (-not (Test-Path $dest)) { New-Item -ItemType Directory -Path $dest -Force | Out-Null }
  $out = Join-Path $dest $f.file

  $cssUrl = "https://fonts.googleapis.com/css2?family=$($f.family):wght@$($f.weight)&display=swap"
  $css = (Invoke-WebRequest -UseBasicParsing -Uri $cssUrl -UserAgent $UA -TimeoutSec 30).Content

  # Take only the block tagged /* latin */ - the smallest subset that covers
  # U+0000-00FF plus the punctuation the UI actually uses.
  $m = [regex]::Match($css, '/\*\s*latin\s*\*/\s*@font-face\s*\{[^}]*?src:\s*url\((?<u>[^)]+)\)')
  if (-not $m.Success) { throw "no latin subset found for $($f.name) $($f.weight)" }

  Invoke-WebRequest -UseBasicParsing -Uri $m.Groups['u'].Value -UserAgent $UA -TimeoutSec 30 -OutFile $out
  $kb = [math]::Round((Get-Item $out).Length / 1KB, 1)
  Write-Output ("{0,-32} {1,4}  ->  skins/{2}/fonts/{3}  ({4} KB)" -f $f.name, $f.weight, $f.skin, $f.file, $kb)
}

# Licenses - OFL requires the notice travel with the fonts.
$licenses = @(
  @{ dir='sairacondensed'; skin='terran';  file='OFL-SairaCondensed.txt' },
  @{ dir='jetbrainsmono';  skin='terran';  file='OFL-JetBrainsMono.txt' },
  @{ dir='cinzel';         skin='protoss'; file='OFL-Cinzel.txt' },
  @{ dir='rajdhani';       skin='protoss'; file='OFL-Rajdhani.txt' },
  @{ dir='spacemono';      skin='protoss'; file='OFL-SpaceMono.txt' },
  @{ dir='metamorphous';   skin='zerg';    file='OFL-Metamorphous.txt' },
  @{ dir='chakrapetch';    skin='zerg';    file='OFL-ChakraPetch.txt' },
  @{ dir='spacemono';      skin='zerg';    file='OFL-SpaceMono.txt' },
  @{ dir='playfairdisplay';  skin='clock-tower-daylight'; file='OFL-PlayfairDisplay.txt' },
  @{ dir='librebaskerville'; skin='clock-tower-daylight'; file='OFL-LibreBaskerville.txt' },
  @{ dir='playfairdisplay';  skin='clock-tower-lantern'; file='OFL-PlayfairDisplay.txt' },
  @{ dir='librebaskerville'; skin='clock-tower-lantern'; file='OFL-LibreBaskerville.txt' }
)

foreach ($l in $licenses) {
  if ($Skins -and ($Skins -notcontains $l.skin)) { continue }
  $dest = Join-Path $root "skins\$($l.skin)\fonts\$($l.file)"
  $url  = "https://raw.githubusercontent.com/google/fonts/main/ofl/$($l.dir)/OFL.txt"
  try {
    Invoke-WebRequest -UseBasicParsing -Uri $url -UserAgent $UA -TimeoutSec 30 -OutFile $dest
    Write-Output "license  -> skins/$($l.skin)/fonts/$($l.file)"
  } catch {
    Write-Output "WARN: could not fetch license for $($l.dir): $($_.Exception.Message)"
  }
}

Write-Output ""
Write-Output "Done."

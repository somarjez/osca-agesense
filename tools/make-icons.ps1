<#
.SYNOPSIS
    Generates resources\branding\osca.ico (Start) and osca-stop.ico (Stop) from the
    AgeSense "A" mark (same design as public\favicon.svg), using GDI+ only — no
    external tools or internet access required.

    Safe to re-run: always overwrites the two .ico files.
#>
#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$PROJECT  = Split-Path -Parent $PSScriptRoot
$OUT_DIR  = Join-Path $PROJECT 'resources\branding'
if (-not (Test-Path $OUT_DIR)) { New-Item -ItemType Directory -Path $OUT_DIR -Force | Out-Null }

# ── Colors (matches public\favicon.svg) ─────────────────────────────────────────
$NAVY  = [System.Drawing.Color]::FromArgb(255, 0x16, 0x21, 0x3a)
$WHITE = [System.Drawing.Color]::FromArgb(255, 0xfb, 0xfa, 0xf6)
$BLUE  = [System.Drawing.Color]::FromArgb(255, 0x56, 0x89, 0xd6)
$RED   = [System.Drawing.Color]::FromArgb(255, 0xb9, 0x1c, 0x1c)

function New-RoundedRectPath {
    param([single]$X, [single]$Y, [single]$W, [single]$H, [single]$Radius)
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $d = $Radius * 2
    $path.AddArc($X, $Y, $d, $d, 180, 90)
    $path.AddArc($X + $W - $d, $Y, $d, $d, 270, 90)
    $path.AddArc($X + $W - $d, $Y + $H - $d, $d, $d, 0, 90)
    $path.AddArc($X, $Y + $H - $d, $d, $d, 90, 90)
    $path.CloseFigure()
    return $path
}

function New-MarkBitmap {
    param([bool]$IsStop, [int]$Size = 256)

    $bmp = New-Object System.Drawing.Bitmap $Size, $Size
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode     = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.Clear([System.Drawing.Color]::Transparent)

    # Background rounded square (radius ratio 11/40 from favicon.svg)
    $bg = if ($IsStop) { $RED } else { $NAVY }
    $radius = $Size * (11.0 / 40.0)
    $bgPath = New-RoundedRectPath -X 0 -Y 0 -W $Size -H $Size -Radius $radius
    $g.FillPath((New-Object System.Drawing.SolidBrush $bg), $bgPath)

    # Subtle inner border (white, low opacity) — matches favicon.svg stroke
    $borderPen = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(18, 255, 255, 255)), ($Size * 0.015)
    $inset = $Size * 0.015
    $borderPath = New-RoundedRectPath -X $inset -Y $inset -W ($Size - 2*$inset) -H ($Size - 2*$inset) -Radius ($radius - $inset)
    $g.DrawPath($borderPen, $borderPath)

    if (-not $IsStop) {
        # "A" mark — two legs + crossbar, proportions taken directly from favicon.svg (40x40 base)
        $scale = $Size / 40.0
        $legWidth = 2.5 * $scale
        $legPen = New-Object System.Drawing.Pen $WHITE, $legWidth
        $legPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
        $legPen.EndCap   = [System.Drawing.Drawing2D.LineCap]::Round
        $g.DrawLine($legPen, 20*$scale, 8.4*$scale, 12*$scale, 31.6*$scale)
        $g.DrawLine($legPen, 20*$scale, 8.4*$scale, 28*$scale, 31.6*$scale)

        $barPen = New-Object System.Drawing.Pen $BLUE, $legWidth
        $barPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
        $barPen.EndCap   = [System.Drawing.Drawing2D.LineCap]::Round
        $g.DrawLine($barPen, 14.7*$scale, 24.3*$scale, 25.3*$scale, 24.3*$scale)
    } else {
        # Universal "stop" glyph — filled white rounded square, centered
        $sq = $Size * 0.34
        $sqOffset = ($Size - $sq) / 2
        $sqPath = New-RoundedRectPath -X $sqOffset -Y $sqOffset -W $sq -H $sq -Radius ($sq * 0.18)
        $g.FillPath((New-Object System.Drawing.SolidBrush $WHITE), $sqPath)
    }

    $g.Dispose()
    return $bmp
}

function Save-MultiResIcon {
    param([bool]$IsStop, [string]$OutPath)

    $master = New-MarkBitmap -IsStop $IsStop -Size 256
    $sizes = @(16, 32, 48, 256)

    $pngBlobs = @()
    foreach ($s in $sizes) {
        if ($s -eq 256) {
            $bmp = $master
        } else {
            $bmp = New-Object System.Drawing.Bitmap $master, $s, $s
        }
        $ms = New-Object System.IO.MemoryStream
        $bmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png)
        $pngBlobs += ,($s, $ms.ToArray())
        if ($s -ne 256) { $bmp.Dispose() }
    }
    $master.Dispose()

    # ── Write ICO container (PNG-compressed frames — supported Vista+) ──────────
    $fs = [System.IO.File]::Open($OutPath, [System.IO.FileMode]::Create)
    $bw = New-Object System.IO.BinaryWriter $fs

    $bw.Write([UInt16]0)           # reserved
    $bw.Write([UInt16]1)           # type = icon
    $bw.Write([UInt16]$pngBlobs.Count)

    $headerSize = 6 + (16 * $pngBlobs.Count)
    $offset = $headerSize
    foreach ($entry in $pngBlobs) {
        $s = $entry[0]; $bytes = $entry[1]
        $wByte = if ($s -ge 256) { 0 } else { $s }
        $bw.Write([Byte]$wByte)    # width
        $bw.Write([Byte]$wByte)    # height
        $bw.Write([Byte]0)         # color count
        $bw.Write([Byte]0)         # reserved
        $bw.Write([UInt16]1)       # planes
        $bw.Write([UInt16]32)      # bit count
        $bw.Write([UInt32]$bytes.Length)
        $bw.Write([UInt32]$offset)
        $offset += $bytes.Length
    }
    foreach ($entry in $pngBlobs) {
        $bw.Write([Byte[]]$entry[1])
    }

    $bw.Flush(); $bw.Close(); $fs.Close()
}

$startIco = Join-Path $OUT_DIR 'osca.ico'
$stopIco  = Join-Path $OUT_DIR 'osca-stop.ico'

Save-MultiResIcon -IsStop $false -OutPath $startIco
Write-Host " [ OK ] Wrote $startIco"

Save-MultiResIcon -IsStop $true -OutPath $stopIco
Write-Host " [ OK ] Wrote $stopIco"

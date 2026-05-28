param(
    [Parameter(Mandatory = $true)]
    [string]$InputPath,

    [Parameter(Mandatory = $true)]
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'

$inputFile = [System.IO.Path]::GetFullPath($InputPath)
$outputFile = [System.IO.Path]::GetFullPath($OutputPath)

if (-not (Test-Path -LiteralPath $inputFile)) {
    throw "Input file not found: $inputFile"
}

$outputDir = Split-Path -Parent $outputFile
if ($outputDir -and -not (Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

$word = $null
$document = $null

try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0

    $document = $word.Documents.Open($inputFile, $false, $true)
    $document.SaveAs([ref]$outputFile, [ref]17)
}
finally {
    if ($document -ne $null) {
        try {
            $document.Close([ref]$false)
        }
        catch {}
    }

    if ($word -ne $null) {
        try {
            $word.Quit()
        }
        catch {}
    }
}

# Run in PowerShell outside this restricted session. No application is started.
# Exports every named page in diagram order using the installed draw.io Desktop.
$drawioExecutable = 'C:\Program Files\draw.io\draw.io.exe'
if (-not (Test-Path -LiteralPath $drawioExecutable)) {
    throw 'draw.io Desktop was not found at the previously detected path.'
}
$architectureDirectory = $PSScriptRoot
$diagramNames = @(
    '01-package-diagram',
    '02-use-case-diagram',
    '03-domain-data-diagram',
    '04-registration-approval-flow',
    '05-attendance-flow',
    '06-assistance-report-flow'
)
foreach ($diagramName in $diagramNames) {
    & $drawioExecutable --export --format pdf --all-pages `
        --output (Join-Path $architectureDirectory "$diagramName.pdf") `
        (Join-Path $architectureDirectory "$diagramName.drawio") `
        "--user-data-dir=$(Join-Path $architectureDirectory '.drawio-profile')"
    if ($LASTEXITCODE -ne 0) { throw "Export failed: $diagramName" }
    $pdfFile = Get-Item -LiteralPath (Join-Path $architectureDirectory "$diagramName.pdf")
    if ($pdfFile.Length -eq 0) { throw "Empty PDF: $diagramName" }
}

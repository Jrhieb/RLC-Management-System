$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Runtime.WindowsRuntime
[Windows.Storage.StorageFile,Windows.Storage,ContentType=WindowsRuntime] | Out-Null
[Windows.Storage.StorageFolder,Windows.Storage,ContentType=WindowsRuntime] | Out-Null
[Windows.Data.Pdf.PdfDocument,Windows.Data.Pdf,ContentType=WindowsRuntime] | Out-Null
[Windows.Data.Pdf.PdfPageRenderOptions,Windows.Data.Pdf,ContentType=WindowsRuntime] | Out-Null
$asTaskGeneric = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.IsGenericMethod })[0]
function Await-Result($Operation, $ResultType) {
    $task = $asTaskGeneric.MakeGenericMethod($ResultType).Invoke($null, @($Operation))
    $task.Wait()
    $task.Result
}
function Await-Action($Operation) {
    $method = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and -not $_.IsGenericMethod -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction' } | Select-Object -First 1
    $task = $method.Invoke($null, @($Operation))
    $task.Wait()
}
$pdfFile = Await-Result ([Windows.Storage.StorageFile]::GetFileFromPathAsync((Join-Path $PSScriptRoot 'RLCP-System-Architecture.pdf'))) ([Windows.Storage.StorageFile])
$document = Await-Result ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($pdfFile)) ([Windows.Data.Pdf.PdfDocument])
Write-Output "PDF page count: $($document.PageCount)"
if ($document.PageCount -ne 6) { throw 'Expected exactly six PDF pages.' }
$folder = Await-Result ([Windows.Storage.StorageFolder]::GetFolderFromPathAsync($PSScriptRoot)) ([Windows.Storage.StorageFolder])
for ($i=0; $i -lt $document.PageCount; $i++) {
    $page = $document.GetPage($i)
    $outFile = Await-Result ($folder.CreateFileAsync("verified-page-$($i+1).png", [Windows.Storage.CreationCollisionOption]::ReplaceExisting)) ([Windows.Storage.StorageFile])
    $stream = Await-Result ($outFile.OpenAsync([Windows.Storage.FileAccessMode]::ReadWrite)) ([Windows.Storage.Streams.IRandomAccessStream])
    $options = New-Object Windows.Data.Pdf.PdfPageRenderOptions
    $options.DestinationWidth = 2200
    Await-Action ($page.RenderToStreamAsync($stream, $options))
    $stream.Dispose()
    $page.Dispose()
    Write-Output "Rendered page $($i+1)"
}

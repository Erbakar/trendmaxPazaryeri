param([Parameter(ValueFromRemainingArguments=$true)][string[]]$BuilderArgs)
$ErrorActionPreference = "Stop"
& python "$PSScriptRoot/build-release.py" @BuilderArgs
if ($LASTEXITCODE -ne 0) { throw "Release builder failed: $LASTEXITCODE" }

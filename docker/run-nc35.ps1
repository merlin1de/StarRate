# NC35 Testinstanz starten (SSH-kompatibel, ohne Windows Credential Store).
#
# Voraussetzung: `nextcloud:35` wurde EINMAL interaktiv gepullt (siehe nc35.yml-Header).
# `compose up` braucht dann keinen Pull mehr und läuft über SSH durch.
$configDir = "$env:USERPROFILE\docker-ssh-config"
New-Item -ItemType Directory -Force -Path $configDir | Out-Null
[System.IO.File]::WriteAllText("$configDir\config.json", '{"auths":{},"credsStore":""}', [System.Text.Encoding]::ASCII)

$env:DOCKER_CONFIG = $configDir
docker --context default compose -f "$env:USERPROFILE\docker-nc35.yml" up -d

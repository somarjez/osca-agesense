' stop-osca.vbs
' Zero-window entry point for the "Stop OSCA System" shortcut.
' Runs stop.ps1 -Quiet hidden (no console), waits for it to finish, then shows
' a small confirmation popup so staff get visible feedback that it worked.
'
' Resolves its own folder at runtime, so this file — and any shortcut
' pointing at it — keeps working no matter where the shortcut is placed.

Dim fso, scriptDir, shell, psCommand

Set fso = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)

Set shell = CreateObject("WScript.Shell")

psCommand = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & scriptDir & "\stop.ps1"" -Quiet"

' Third argument True = wait for it to finish before showing the popup below.
shell.Run psCommand, 0, True

MsgBox "OSCA System has been stopped.", vbInformation, "AgeSense OSCA"

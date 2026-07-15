' launch-osca.vbs
' Zero-window entry point for the "Start OSCA System" shortcut.
' Run via wscript.exe (not cscript.exe) so nothing flashes on screen —
' not even a console for the split second PowerShell takes to spin up.
'
' Resolves its own folder at runtime (via WScript.ScriptFullName), so this
' file — and any shortcut pointing at it — keeps working no matter where the
' shortcut icon itself is placed (Desktop, Start Menu, copied elsewhere).

Dim fso, scriptDir, shell, psCommand

Set fso = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)

Set shell = CreateObject("WScript.Shell")

psCommand = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & scriptDir & "\start-quiet.ps1"""

' Third argument False = don't wait for it to finish; 0 = hidden window style.
shell.Run psCommand, 0, False

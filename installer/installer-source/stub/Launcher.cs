// Starts the PowerShell script next to this program that has the same name
// (BSU-Inventory.exe runs BSU-Inventory.ps1) without a console window, so shortcuts open
// cleanly and carry the BSU icon. Compiled by build.ps1 with the .NET Framework compiler
// that ships with Windows; the Server Manager copy gets a manifest asking for admin rights.
using System;
using System.Diagnostics;
using System.IO;
using System.Reflection;
using System.Text;

[assembly: AssemblyCompany("Benguet State University")]
[assembly: AssemblyProduct("BSU Inventory System")]
[assembly: AssemblyTitle("BSU Inventory System")]

internal static class Launcher
{
    [STAThread]
    private static int Main(string[] args)
    {
        string exe = Assembly.GetEntryAssembly().Location;
        string script = Path.ChangeExtension(exe, ".ps1");
        if (!File.Exists(script))
        {
            return 2;
        }

        var arguments = new StringBuilder("-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"" + script + "\"");
        foreach (string a in args)
        {
            arguments.Append(" \"").Append(a.Replace("\"", "\\\"")).Append('"');
        }

        var start = new ProcessStartInfo(
            Path.Combine(Environment.SystemDirectory, @"WindowsPowerShell\v1.0\powershell.exe"),
            arguments.ToString())
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            WorkingDirectory = Path.GetDirectoryName(exe),
        };
        Process.Start(start);
        return 0;
    }
}

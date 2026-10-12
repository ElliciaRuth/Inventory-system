; BSU Inventory System - Windows installer (Inno Setup 6).
;
; Do not compile this file directly: build.ps1 prepares the files it packages (BSU's own
; Laragon 6 copy with current Apache, PHP and MariaDB, the production build of the app,
; the server scripts) and passes these defines:
#ifndef AppVersion
  #error Build with installer-source\build.ps1 (it defines AppVersion, StageDir, OutputDir and the component versions).
#endif
;   AppVersion, StageDir, OutputDir, LaragonVersion, ApacheVersion, PhpVersion, MariaDbVersion
;
; What it does on the server PC (all offline):
;   1. wizard: install folder, network (LAN access, ports), first administrator account,
;      backup folder; then a check of this PC (ports, other Laragon installs, LAN address)
;   2. before an update: a full backup and stopping the services (manager\pre-update.ps1)
;   3. copies the files; installs the Visual C++ runtime
;   4. configures everything (manager\configure.ps1): php.ini, MariaDB and Apache as Windows
;      services, database + its own account, .env, migrations, HTTPS, firewall, backups
; The uninstaller asks what to keep and never deletes the database without asking.

#define AppName "BSU Inventory System"
#define AppPublisher "Benguet State University"

[Setup]
AppId={{FC7BDDE8-7191-4A10-8AB9-DD437265AAC5}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher={#AppPublisher}
VersionInfoVersion={#AppVersion}
VersionInfoProductName={#AppName}
VersionInfoDescription={#AppName} Setup
DefaultDirName=C:\BSU-Inventory
DisableDirPage=auto
UsePreviousAppDir=yes
DirExistsWarning=no
DefaultGroupName=BSU Inventory
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
; Windows 10 1809 / Server 2019 or newer
MinVersion=10.0.17763
OutputDir={#OutputDir}
OutputBaseFilename=BSU-Inventory-Setup
SetupIconFile={#StageDir}\manager\bsu-inventory.ico
UninstallDisplayIcon={app}\manager\bsu-inventory.ico
UninstallDisplayName={#AppName}
UninstallFilesDir={app}\uninstall
WizardStyle=modern
Compression=lzma2/max
SolidCompression=yes
LZMANumBlockThreads=4
SetupLogging=yes
CloseApplications=no
RestartApplications=no
AllowNoIcons=no
ExtraDiskSpaceRequired=1073741824

[Messages]
WelcomeLabel2=This installs the [name/ver] on this computer, which becomes the BSU Inventory server for the local network.%n%nEverything it needs is included (Laragon with Apache, PHP and MariaDB); no internet connection is needed. Other computers only need a web browser.%n%nClose other programs that use the ports 80 and 443 (for example XAMPP or IIS) before you continue.

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut (BSU Inventory)"; GroupDescription: "Shortcuts:"

[Files]
; BSU's own Laragon copy, with the application in laragon\www\bsu-inventory.
; backend\.env, the database and uploaded files are never part of the package, so an update
; cannot overwrite them; the empty writable folders are added only when missing.
Source: "{#StageDir}\laragon\*"; DestDir: "{app}\laragon"; Flags: ignoreversion recursesubdirs createallsubdirs; Excludes: "\www\bsu-inventory\backend\writable\*,\www\bsu-inventory\backend\public\barcodes\*"
Source: "{#StageDir}\laragon\www\bsu-inventory\backend\writable\*"; DestDir: "{app}\laragon\www\bsu-inventory\backend\writable"; Flags: onlyifdoesntexist recursesubdirs createallsubdirs
Source: "{#StageDir}\laragon\www\bsu-inventory\backend\public\barcodes\*"; DestDir: "{app}\laragon\www\bsu-inventory\backend\public\barcodes"; Flags: onlyifdoesntexist recursesubdirs createallsubdirs skipifsourcedoesntexist
Source: "{#StageDir}\manager\*"; DestDir: "{app}\manager"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#StageDir}\vc_redist.x64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall
; Needed before the files are installed (checks, and the backup before an update)
Source: "{#StageDir}\manager\checks.ps1"; Flags: dontcopy
Source: "{#StageDir}\manager\common.ps1"; Flags: dontcopy
Source: "{#StageDir}\manager\backup.ps1"; Flags: dontcopy
Source: "{#StageDir}\manager\pre-update.ps1"; Flags: dontcopy

[InstallDelete]
; Program folders are replaced as a whole on update (old versions' files must not linger).
; Data lives elsewhere: laragon\data, backend\.env, writable\, public\barcodes, config\.
Type: filesandordirs; Name: "{app}\laragon\bin\apache"
Type: filesandordirs; Name: "{app}\laragon\bin\php"
Type: filesandordirs; Name: "{app}\laragon\bin\mysql"
Type: filesandordirs; Name: "{app}\laragon\www\bsu-inventory\backend\app"
Type: filesandordirs; Name: "{app}\laragon\www\bsu-inventory\backend\vendor"
Type: filesandordirs; Name: "{app}\laragon\www\bsu-inventory\frontend\dist"
Type: filesandordirs; Name: "{app}\laragon\www\bsu-inventory\backend\writable\cache"
Type: filesandordirs; Name: "{app}\manager"

[Icons]
Name: "{autoprograms}\BSU Inventory\BSU Inventory"; Filename: "{app}\manager\BSU-Inventory.exe"; IconFilename: "{app}\manager\bsu-inventory.ico"; Comment: "Open the BSU Inventory System"
Name: "{autoprograms}\BSU Inventory\BSU Inventory Server Manager"; Filename: "{app}\manager\BSU-Inventory-Manager.exe"; IconFilename: "{app}\manager\bsu-inventory.ico"; Comment: "Server status, start/stop, backups, logs"
Name: "{autoprograms}\BSU Inventory\How other computers connect"; Filename: "{app}\config\connection-info.txt"
Name: "{autoprograms}\BSU Inventory\Uninstall BSU Inventory"; Filename: "{uninstallexe}"
Name: "{autodesktop}\BSU Inventory"; Filename: "{app}\manager\BSU-Inventory.exe"; IconFilename: "{app}\manager\bsu-inventory.ico"; Tasks: desktopicon

[Run]
Filename: "{app}\manager\BSU-Inventory.exe"; Description: "Open BSU Inventory now"; Flags: postinstall nowait skipifsilent runasoriginaluser; Check: ConfigureSucceeded
Filename: "{win}\notepad.exe"; Parameters: """{app}\config\connection-info.txt"""; Description: "Show how other computers connect"; Flags: postinstall nowait skipifsilent unchecked; Check: ConfigureSucceeded

[Code]
const
  UninstallKey = 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{FC7BDDE8-7191-4A10-8AB9-DD437265AAC5}_is1';
  Tick = #$2713;
  Cross = #$2717;
  Dot = #$2022;

var
  IsExisting: Boolean;          // update, or reinstall over kept data: keep its settings
  ConfigureOk: Boolean;
  Facts: TArrayOfString;        // output of checks.ps1
  NetworkPage: TInputQueryWizardPage;
  LanCheck: TNewCheckBox;
  NetworkInfo: TNewStaticText;
  AdminPage: TInputQueryWizardPage;
  BackupPage: TInputDirWizardPage;
  DailyCheck: TNewCheckBox;
  TimeLabel: TNewStaticText;
  TimeEdit: TNewEdit;
  ChecksPage: TOutputMsgMemoWizardPage;
  ChecksBlocking: Boolean;      // a port is taken or the disk is full
  // Uninstall choices
  UnBackup, UnDatabase, UnUploads, UnConfig, UnLogs, UnBackups: Boolean;

// ---------------------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------------------
function PowerShellExe: String;
begin
  Result := ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe');
end;

function ConfigureSucceeded: Boolean;
begin
  Result := ConfigureOk;
end;

function StartsText(const Prefix, S: String): Boolean;
begin
  Result := Copy(S, 1, Length(Prefix)) = Prefix;
end;

// Word number N (1-based) of a space-separated line; Rest = everything from word N on
function Field(const S: String; N: Integer; Rest: Boolean): String;
var
  I, Count, Start: Integer;
begin
  Result := '';
  Count := 0;
  I := 1;
  while I <= Length(S) do
  begin
    while (I <= Length(S)) and (S[I] = ' ') do I := I + 1;
    if I > Length(S) then Break;
    Start := I;
    while (I <= Length(S)) and (S[I] <> ' ') do I := I + 1;
    Count := Count + 1;
    if Count = N then
    begin
      if Rest then Result := Trim(Copy(S, Start, Length(S))) else Result := Copy(S, Start, I - Start);
      Exit;
    end;
  end;
end;

function JsonEscape(const S: String): String;
var
  I: Integer;
  C: Char;
begin
  Result := '';
  for I := 1 to Length(S) do
  begin
    C := S[I];
    if C = '"' then Result := Result + '\"'
    else if C = '\' then Result := Result + '\\'
    else if Ord(C) < 32 then Result := Result + Format('\u%.4x', [Ord(C)])
    else Result := Result + C;
  end;
end;

procedure RunChecks(const Dir, Ports: String);
var
  ResultCode: Integer;
  Output: TExecOutput;
begin
  SetArrayLength(Facts, 0);
  if ExecAndCaptureOutput(PowerShellExe, '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{tmp}\checks.ps1') +
       '" -InstallDir "' + Dir + '" -Ports "' + Ports + '"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode, Output) then
    Facts := Output.StdOut;
  Log('Checks: ' + IntToStr(GetArrayLength(Facts)) + ' facts');
end;

// The PORT line for a port: FREE, OURS or USED <process> <path>
function PortFact(Port: String): String;
var
  I: Integer;
begin
  Result := 'FREE';
  for I := 0 to GetArrayLength(Facts) - 1 do
    if StartsText('PORT ' + Port + ' ', Facts[I]) then
      Result := Field(Facts[I], 3, True);
end;

function HttpPort: String;  begin Result := Trim(NetworkPage.Values[0]); end;
function HttpsPort: String; begin Result := Trim(NetworkPage.Values[1]); end;

function IsDigits(const S: String): Boolean;
var
  I: Integer;
begin
  Result := Length(S) > 0;
  for I := 1 to Length(S) do
    if (S[I] < '0') or (S[I] > '9') then Result := False;
end;

// ---------------------------------------------------------------------------
//  Validation (the server checks the same rules again)
// ---------------------------------------------------------------------------
function NameError(const S, Lbl: String): String;
var
  I: Integer;
  C: Char;
begin
  Result := '';
  if (Length(S) < 2) or (Length(S) > 100) then begin Result := Lbl + ' must be 2 to 100 characters long.'; Exit; end;
  for I := 1 to Length(S) do
  begin
    C := S[I];
    if not (((C >= 'A') and (C <= 'Z')) or ((C >= 'a') and (C <= 'z')) or (Ord(C) > 127) or
            (((C = ' ') or (C = '.') or (C = '''') or (C = '-')) and (I > 1))) then
    begin
      Result := Lbl + ' can only contain letters, spaces, hyphens, apostrophes and periods, and must start with a letter.';
      Exit;
    end;
  end;
end;

function UsernameError(const S: String): String;
var
  I: Integer;
  C: Char;
begin
  Result := '';
  if (Length(S) < 3) or (Length(S) > 50) then begin Result := 'Username must be 3 to 50 characters long.'; Exit; end;
  for I := 1 to Length(S) do
  begin
    C := S[I];
    if not (((C >= 'A') and (C <= 'Z')) or ((C >= 'a') and (C <= 'z')) or ((C >= '0') and (C <= '9')) or (C = '.') or (C = '_') or (C = '-')) then
    begin
      Result := 'Username can only contain letters, digits, dots, hyphens and underscores.';
      Exit;
    end;
  end;
end;

function EmailError(const S: String): String;
var
  At: Integer;
begin
  Result := '';
  At := Pos('@', S);
  if (At < 2) or (Pos('.', Copy(S, At + 1, Length(S))) < 2) or (Pos(' ', S) > 0) or (S[Length(S)] = '.') or (Length(S) > 255) then
    Result := 'Enter a valid email address (it is used for "Forgot password").';
end;

// Same rules as App\Libraries\PasswordPolicy
function PasswordError(const P: String): String;
var
  I: Integer;
  HasUpper, HasLower, HasDigit, Sequence: Boolean;
begin
  Result := '';
  HasUpper := False; HasLower := False; HasDigit := False; Sequence := False;
  for I := 1 to Length(P) do
  begin
    if (P[I] >= 'A') and (P[I] <= 'Z') then HasUpper := True;
    if (P[I] >= 'a') and (P[I] <= 'z') then HasLower := True;
    if (P[I] >= '0') and (P[I] <= '9') then HasDigit := True;
    if (I <= Length(P) - 2) and (P[I] >= '0') and (P[I] <= '7') and (Ord(P[I + 1]) = Ord(P[I]) + 1) and (Ord(P[I + 2]) = Ord(P[I]) + 2) then
      Sequence := True;
  end;
  if Length(P) < 8 then Result := 'The password must be at least 8 characters long.'
  else if Length(P) > 72 then Result := 'The password must be at most 72 characters long.'
  else if not HasUpper then Result := 'The password must contain an uppercase letter.'
  else if not HasLower then Result := 'The password must contain a lowercase letter.'
  else if not HasDigit then Result := 'The password must contain a number.'
  else if Sequence then Result := 'The password must not contain sequential numbers (e.g. 123, 456).'
  else if (Lowercase(P) = 'admin123') or (Lowercase(P) = 'password') then Result := 'Choose a password that is not a common default.';
end;

function InstallDirError(const D: String): String;
var
  I: Integer;
begin
  Result := '';
  if Length(D) < 4 then begin Result := 'Choose a folder, not the root of a drive (for example C:\BSU-Inventory).'; Exit; end;
  // Files deep inside the application must stay under Windows' 260-character path limit
  if Length(D) > 60 then begin Result := 'Choose a shorter folder path (at most 60 characters), for example C:\BSU-Inventory.'; Exit; end;
  for I := 1 to Length(D) do
    if (D[I] = ' ') or (Ord(D[I]) > 127) then
    begin
      Result := 'Choose a folder without spaces or accented letters (for example C:\BSU-Inventory). Laragon, Apache and MariaDB work most reliably that way.';
      Exit;
    end;
end;

// ---------------------------------------------------------------------------
//  Wizard
// ---------------------------------------------------------------------------
function ExistingInstall(const Dir: String): Boolean;
begin
  Result := FileExists(AddBackslash(Dir) + 'config\install.json');
end;

// No finished first setup yet (new, or an earlier attempt failed): ask for the administrator
function NeedsAdmin: Boolean;
begin
  Result := not FileExists(AddBackslash(WizardDirValue) + 'config\setup-complete');
end;

function InitializeSetup: Boolean;
var
  Prev: String;
  PrevPacked, NewPacked: Int64;
begin
  Result := True;
  if RegQueryStringValue(HKLM64, UninstallKey, 'DisplayVersion', Prev) then
    if StrToVersion(Prev, PrevPacked) and StrToVersion('{#AppVersion}', NewPacked) and (ComparePackedVersion(PrevPacked, NewPacked) > 0) then
      Result := SuppressibleMsgBox('A newer version (' + Prev + ') of the BSU Inventory System is installed. Installing the older {#AppVersion} over it is not supported and may break the database.' + #13#10#13#10 +
                       'Continue anyway?', mbError, MB_YESNO or MB_DEFBUTTON2, IDNO) = IDYES;
end;

function DefaultBackupDir: String;
var
  I: Integer;
  Drive, InstallDrive: String;
begin
  InstallDrive := Uppercase(Copy(WizardDirValue, 1, 2));
  Result := InstallDrive + '\BSU Inventory Backups';
  // Prefer another hard disk: a backup on the same disk doesn't survive a disk failure
  for I := 0 to GetArrayLength(Facts) - 1 do
    if StartsText('FIXEDDRIVE ', Facts[I]) then
    begin
      Drive := Uppercase(Field(Facts[I], 2, False));
      if (Drive <> InstallDrive) and (StrToIntDef(Field(Facts[I], 3, False), 0) >= 5) then
      begin
        Result := Drive + '\BSU Inventory Backups';
        Exit;
      end;
    end;
end;

// Puts field R next to field L (two columns) and moves the fields below it up one row
procedure PairFields(Page: TInputQueryWizardPage; L, R, Count: Integer);
var
  Gap, Half, Row, I: Integer;
begin
  Gap := ScaleX(12);
  Half := (Page.SurfaceWidth - Gap) div 2;
  Row := Page.PromptLabels[R].Top - Page.PromptLabels[L].Top;
  Page.PromptLabels[L].Width := Half;
  Page.Edits[L].Width := Half;
  Page.PromptLabels[R].Left := Page.Edits[L].Left + Half + Gap;
  Page.PromptLabels[R].Top := Page.PromptLabels[L].Top;
  Page.PromptLabels[R].Width := Half;
  Page.Edits[R].Left := Page.PromptLabels[R].Left;
  Page.Edits[R].Top := Page.Edits[L].Top;
  Page.Edits[R].Width := Half;
  for I := R + 1 to Count - 1 do
  begin
    Page.PromptLabels[I].Top := Page.PromptLabels[I].Top - Row;
    Page.Edits[I].Top := Page.Edits[I].Top - Row;
  end;
end;

procedure InitializeWizard;
var
  Y: Integer;
begin
  ExtractTemporaryFile('checks.ps1');
  IsExisting := ExistingInstall(WizardDirValue);

  // Network
  NetworkPage := CreateInputQueryPage(wpSelectDir, 'Network (LAN access)',
    'How other computers on the BSU network reach this server.',
    'Other computers open the system in a web browser. The database is never reachable from them.');
  NetworkPage.Add('HTTP port (redirects to HTTPS; serves the certificate devices install once):', False);
  NetworkPage.Add('HTTPS port (the system itself):', False);
  // Defaults, or values given on the command line (silent installs, see README)
  NetworkPage.Values[0] := ExpandConstant('{param:HTTPPORT|80}');
  NetworkPage.Values[1] := ExpandConstant('{param:HTTPSPORT|443}');
  Y := NetworkPage.Edits[1].Top + NetworkPage.Edits[1].Height + ScaleY(14);
  LanCheck := TNewCheckBox.Create(NetworkPage);
  LanCheck.Parent := NetworkPage.Surface;
  LanCheck.Top := Y;
  LanCheck.Width := NetworkPage.SurfaceWidth;
  LanCheck.Caption := 'Enable LAN access (open these two ports in the Windows firewall for private networks)';
  LanCheck.Checked := ExpandConstant('{param:LAN|1}') <> '0';
  NetworkInfo := TNewStaticText.Create(NetworkPage);
  NetworkInfo.Parent := NetworkPage.Surface;
  NetworkInfo.Top := Y + ScaleY(28);
  NetworkInfo.Width := NetworkPage.SurfaceWidth;
  NetworkInfo.AutoSize := False;
  NetworkInfo.Height := ScaleY(100);
  NetworkInfo.WordWrap := True;

  // First administrator
  AdminPage := CreateInputQueryPage(NetworkPage.ID, 'Administrator account',
    'The first Technical Staff account. No account with a default password is created.',
    'Password: 8+ characters with upper- and lowercase letters and a number, no sequences like 123.');
  AdminPage.Add('First name:', False);
  AdminPage.Add('Last name:', False);
  AdminPage.Add('Username:', False);
  AdminPage.Add('Email (for "Forgot password"):', False);
  AdminPage.Add('Password:', True);
  AdminPage.Add('Confirm password:', True);
  // Six fields don't fit one below the other: names and passwords go side by side
  PairFields(AdminPage, 0, 1, 6);
  PairFields(AdminPage, 4, 5, 6);

  // Backups
  BackupPage := CreateInputDirPage(AdminPage.ID, 'Backups',
    'Where full backups of the database, configuration and uploaded files are kept.',
    'Choose a folder outside the install folder, preferably on another disk or a network/USB drive that stays connected.',
    False, '');
  BackupPage.Add('Backup folder:');
  BackupPage.Values[0] := ExpandConstant('{param:BACKUPDIR|}');
  Y := BackupPage.Edits[0].Top + BackupPage.Edits[0].Height + ScaleY(16);
  DailyCheck := TNewCheckBox.Create(BackupPage);
  DailyCheck.Parent := BackupPage.Surface;
  DailyCheck.Top := Y;
  DailyCheck.Width := BackupPage.SurfaceWidth;
  DailyCheck.Caption := 'Back up automatically every day (the newest 30 are kept)';
  DailyCheck.Checked := ExpandConstant('{param:DAILYBACKUP|1}') <> '0';
  TimeLabel := TNewStaticText.Create(BackupPage);
  TimeLabel.Parent := BackupPage.Surface;
  TimeLabel.Top := Y + ScaleY(30);
  TimeLabel.Caption := 'Time (24-hour, HH:MM; a backup missed while the PC was off runs when it is back on):';
  TimeEdit := TNewEdit.Create(BackupPage);
  TimeEdit.Parent := BackupPage.Surface;
  TimeEdit.Top := Y + ScaleY(48);
  TimeEdit.Width := ScaleX(80);
  TimeEdit.Text := ExpandConstant('{param:BACKUPTIME|17:30}');

  // Checks
  ChecksPage := CreateOutputMsgMemoPage(BackupPage.ID, 'Checking this computer',
    'What will be installed, and whether this PC is ready.', 'Review the results, then click Next.', '');
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := (IsExisting and ((PageID = NetworkPage.ID) or (PageID = BackupPage.ID))) or
            ((PageID = AdminPage.ID) and not NeedsAdmin);
end;

procedure DescribeNetwork;
var
  I, Count: Integer;
  Text, Dhcp: String;
begin
  Text := '';
  Count := 0;
  Dhcp := '';
  for I := 0 to GetArrayLength(Facts) - 1 do
    if StartsText('IP ', Facts[I]) then
    begin
      Count := Count + 1;
      Text := Text + '   ' + Field(Facts[I], 2, False) + '   (' + Field(Facts[I], 4, True) + ')' + #13#10;
      if Field(Facts[I], 3, False) = 'DHCP' then Dhcp := 'yes';
    end;
  if Count = 0 then
    Text := 'No LAN address found. Connect this PC to the office network; the system still installs.'
  else
  begin
    Text := 'This PC''s LAN address' + #13#10 + Text;
    if Dhcp <> '' then
      Text := Text + 'The address comes from DHCP and may change: ask the network administrator for a DHCP reservation (or a static address). Windows network settings are not changed by this installer.';
  end;
  NetworkInfo.Caption := Text;
end;

procedure BuildChecks;
var
  S, F, Ports: String;
  I: Integer;
  Blocking: Boolean;
begin
  if IsExisting then Ports := '80,443' else Ports := HttpPort + ',' + HttpsPort;
  RunChecks(WizardDirValue, Ports + ',3306');
  Blocking := False;
  S := 'Server environment (installed into ' + AddBackslash(WizardDirValue) + 'laragon, BSU''s own copy):' + #13#10;
  S := S + '  ' + Tick + ' Laragon {#LaragonVersion}' + #13#10;
  S := S + '  ' + Tick + ' Apache {#ApacheVersion} web server (Windows service, starts with Windows)' + #13#10;
  S := S + '  ' + Tick + ' PHP {#PhpVersion} with intl, mbstring, mysqli, openssl, zip, gd, fileinfo, OPcache' + #13#10;
  S := S + '  ' + Tick + ' MariaDB {#MariaDbVersion} database (Windows service, this PC only)' + #13#10;
  for I := 0 to GetArrayLength(Facts) - 1 do
    if StartsText('VCREDIST ', Facts[I]) then
    begin
      if Field(Facts[I], 2, False) = 'NONE' then
        S := S + '  ' + Tick + ' Visual C++ runtime: will be installed' + #13#10
      else
        S := S + '  ' + Tick + ' Visual C++ runtime: ' + Field(Facts[I], 2, False) + ' found (updated if older)' + #13#10;
    end;
  S := S + '  ' + Tick + ' BSU Inventory System {#AppVersion}: production build (no Composer, Node.js or npm needed)' + #13#10;
  for I := 0 to GetArrayLength(Facts) - 1 do
    if StartsText('LARAGON ', Facts[I]) then
      S := S + '  ' + Dot + ' Another Laragon is installed at ' + Field(Facts[I], 2, True) + '. It is not used or changed.' + #13#10;

  S := S + #13#10 + 'Ports:' + #13#10;
  if not IsExisting then
  begin
    F := PortFact(HttpPort);
    if (F = 'FREE') or (F = 'OURS') then S := S + '  ' + Tick + ' ' + HttpPort + ' (HTTP) is free' + #13#10
    else begin S := S + '  ' + Cross + ' ' + HttpPort + ' (HTTP) is used by ' + Field(F, 2, True) + #13#10; Blocking := True; end;
    F := PortFact(HttpsPort);
    if (F = 'FREE') or (F = 'OURS') then S := S + '  ' + Tick + ' ' + HttpsPort + ' (HTTPS) is free' + #13#10
    else begin S := S + '  ' + Cross + ' ' + HttpsPort + ' (HTTPS) is used by ' + Field(F, 2, True) + #13#10; Blocking := True; end;
    F := PortFact('3306');
    if (F = 'FREE') or (F = 'OURS') then S := S + '  ' + Tick + ' 3306 (database, this PC only) is free' + #13#10
    else S := S + '  ' + Dot + ' 3306 is used by ' + Field(F, 2, True) + '; the BSU database uses the next free port (not opened in the firewall)' + #13#10;
    if LanCheck.Checked then S := S + '  ' + Tick + ' Firewall: TCP ' + HttpPort + ' and ' + HttpsPort + ' opened for LAN addresses on private networks' + #13#10
    else S := S + '  ' + Dot + ' LAN access off: only this PC can use the system' + #13#10;
  end else
    S := S + '  ' + Tick + ' Update: the current ports and settings are kept. A full backup is made and the services are stopped first.' + #13#10;

  S := S + #13#10 + 'Network:' + #13#10;
  F := '';
  for I := 0 to GetArrayLength(Facts) - 1 do
  begin
    if StartsText('IP ', Facts[I]) then
    begin
      S := S + '  ' + Tick + ' LAN address ' + Field(Facts[I], 2, False) + ' (' + Field(Facts[I], 4, True) + ')';
      if Field(Facts[I], 3, False) = 'DHCP' then begin S := S + ' - from DHCP, may change'; F := 'dhcp'; end;
      S := S + #13#10;
    end;
    if StartsText('PUBLICNET ', Facts[I]) then
      S := S + '  ' + Cross + ' "' + Field(Facts[I], 2, True) + '" is a Public network: other PCs are blocked until it is set to Private (Windows Settings > Network & internet).' + #13#10;
  end;
  if F <> '' then S := S + '     Ask for a DHCP reservation for this PC. After an address change, run Repair in the Server Manager.' + #13#10;

  for I := 0 to GetArrayLength(Facts) - 1 do
    if StartsText('DISKFREE ', Facts[I]) then
    begin
      if StrToIntDef(Field(Facts[I], 3, False), 0) < 3 then
      begin
        S := S + #13#10 + Cross + ' Only ' + Field(Facts[I], 3, False) + ' GB free on ' + Field(Facts[I], 2, False) + ' (at least 3 GB needed).' + #13#10;
        Blocking := True;
      end else
        S := S + #13#10 + Tick + ' ' + Field(Facts[I], 3, False) + ' GB free on ' + Field(Facts[I], 2, False) + #13#10;
    end;

  ChecksBlocking := Blocking;
  if Blocking then
    S := S + #13#10 + 'Fix the items marked ' + Cross + ' first: stop the program using the port (for example XAMPP, IIS or another Laragon), or go Back and choose other ports.';
  ChecksPage.RichEditViewer.Text := S;
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if CurPageID = NetworkPage.ID then
  begin
    if GetArrayLength(Facts) = 0 then RunChecks(WizardDirValue, '80,443,3306');
    DescribeNetwork;
  end;
  if (CurPageID = BackupPage.ID) and (BackupPage.Values[0] = '') then
    BackupPage.Values[0] := DefaultBackupDir;
  if CurPageID = ChecksPage.ID then
    BuildChecks;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Err, Dir, T: String;
begin
  Result := True;
  if CurPageID = wpSelectDir then
  begin
    Err := InstallDirError(WizardDirValue);
    if Err <> '' then begin SuppressibleMsgBox(Err, mbError, MB_OK, IDOK); Result := False; Exit; end;
    IsExisting := ExistingInstall(WizardDirValue);
    if IsExisting then
      SuppressibleMsgBox('BSU Inventory data already exists in this folder. It will be kept and updated; its settings are kept.', mbInformation, MB_OK, IDOK)
    else if DirExists(WizardDirValue) and not DirExists(AddBackslash(WizardDirValue) + 'laragon') then
      Result := SuppressibleMsgBox(WizardDirValue + ' already exists. Install into it anyway?', mbConfirmation, MB_YESNO, IDYES) = IDYES;
  end
  else if CurPageID = NetworkPage.ID then
  begin
    if not IsDigits(HttpPort) or not IsDigits(HttpsPort) or (StrToIntDef(HttpPort, 0) < 1) or (StrToIntDef(HttpPort, 0) > 65535) or
       (StrToIntDef(HttpsPort, 0) < 1) or (StrToIntDef(HttpsPort, 0) > 65535) or (HttpPort = HttpsPort) or (HttpPort = '3306') or (HttpsPort = '3306') then
    begin
      SuppressibleMsgBox('Enter two different port numbers between 1 and 65535 (normally 80 and 443).', mbError, MB_OK, IDOK);
      Result := False;
    end;
  end
  else if (CurPageID = AdminPage.ID) and not WizardSilent then   // silent: details come from /ADMINFILE
  begin
    Err := NameError(Trim(AdminPage.Values[0]), 'First name');
    if Err = '' then Err := NameError(Trim(AdminPage.Values[1]), 'Last name');
    if Err = '' then Err := UsernameError(Trim(AdminPage.Values[2]));
    if Err = '' then Err := EmailError(Trim(AdminPage.Values[3]));
    if Err = '' then Err := PasswordError(AdminPage.Values[4]);
    if (Err = '') and (AdminPage.Values[4] <> AdminPage.Values[5]) then Err := 'The two passwords do not match.';
    if (Err = '') and (Lowercase(AdminPage.Values[4]) = Lowercase(Trim(AdminPage.Values[2]))) then Err := 'The password must not be the username.';
    if Err <> '' then begin MsgBox(Err, mbError, MB_OK); Result := False; end;
  end
  else if CurPageID = BackupPage.ID then
  begin
    if Trim(BackupPage.Values[0]) = '' then
    begin
      if GetArrayLength(Facts) = 0 then RunChecks(WizardDirValue, '80');
      BackupPage.Values[0] := DefaultBackupDir;
    end;
    Dir := RemoveBackslashUnlessRoot(BackupPage.Values[0]);
    if (Length(Dir) < 4) or (Pos(Lowercase(AddBackslash(WizardDirValue)), Lowercase(AddBackslash(Dir))) = 1) then
    begin
      SuppressibleMsgBox('Choose a backup folder outside the install folder (and not the root of a drive).', mbError, MB_OK, IDOK);
      Result := False;
      Exit;
    end;
    T := Trim(TimeEdit.Text);
    if DailyCheck.Checked and ((Length(T) <> 5) or (T[3] <> ':') or not IsDigits(Copy(T, 1, 2)) or not IsDigits(Copy(T, 4, 2)) or
       (StrToInt(Copy(T, 1, 2)) > 23) or (StrToInt(Copy(T, 4, 2)) > 59)) then
    begin
      SuppressibleMsgBox('Enter the backup time as HH:MM, for example 17:30.', mbError, MB_OK, IDOK);
      Result := False;
      Exit;
    end;
    if Uppercase(Copy(Dir, 1, 2)) = Uppercase(Copy(WizardDirValue, 1, 2)) then
      Result := SuppressibleMsgBox('The backups would be on the same disk as the system, so a disk failure would lose both.' + #13#10#13#10 +
                       'Continue? (Then copy the backups to another drive regularly.)', mbConfirmation, MB_YESNO, IDYES) = IDYES;
  end
  else if CurPageID = ChecksPage.ID then
  begin
    // Check again: the administrator may have closed XAMPP/IIS meanwhile
    BuildChecks;
    if ChecksBlocking then
    begin
      SuppressibleMsgBox('This PC is not ready yet: see the items marked ' + Cross + '. Fix them (or go Back and choose other ports), then click Next again.', mbError, MB_OK, IDOK);
      Result := False;
    end;
  end;
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
begin
  Result := '';
  if NeedsAdmin and WizardSilent and not FileExists(ExpandConstant('{param:ADMINFILE|}')) then
  begin
    Result := 'A silent first installation needs the administrator account: /ADMINFILE="<file>.json" (see the README).';
    Exit;
  end;
  if not IsExisting then Exit;
  // An update starts with a full backup made by the CURRENT version's tools
  ExtractTemporaryFile('common.ps1');
  ExtractTemporaryFile('backup.ps1');
  ExtractTemporaryFile('pre-update.ps1');
  WizardForm.PreparingLabel.Caption := 'Backing up the current data and stopping the services...';
  if not Exec(PowerShellExe, '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{tmp}\pre-update.ps1') + '" -InstallDir "' + WizardDirValue + '"',
              '', SW_HIDE, ewWaitUntilTerminated, ResultCode) or (ResultCode <> 0) then
    Result := 'The backup before the update failed, so nothing was changed.' + #13#10 +
              'See ' + AddBackslash(WizardDirValue) + 'logs\backup.log and the newest update-*.log there.';
end;

function BoolArg(B: Boolean): String;
begin
  if B then Result := '1' else Result := '0';
end;

procedure RunConfigure;
var
  Params, AdminFile: String;
  Lines: TArrayOfString;
  ResultCode: Integer;
begin
  WizardForm.StatusLabel.Caption := 'Installing the Visual C++ runtime...';
  Exec(ExpandConstant('{tmp}\vc_redist.x64.exe'), '/install /quiet /norestart', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Log('vc_redist exit code ' + IntToStr(ResultCode));

  Params := '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\manager\configure.ps1') + '" -InstallDir "' + ExpandConstant('{app}') +
            '" -Version "{#AppVersion}"';
  if IsExisting then
    Params := Params + ' -Mode Upgrade'
  else
  begin
    if BackupPage.Values[0] = '' then
    begin
      // Silent install without /BACKUPDIR: the same suggestion the wizard makes
      RunChecks(WizardDirValue, '80');
      BackupPage.Values[0] := DefaultBackupDir;
    end;
    Params := Params + ' -Mode Install -HttpPort ' + HttpPort + ' -HttpsPort ' + HttpsPort + ' -LanAccess ' + BoolArg(LanCheck.Checked) +
              ' -BackupDir "' + RemoveBackslashUnlessRoot(BackupPage.Values[0]) + '" -DailyBackup ' + BoolArg(DailyCheck.Checked) +
              ' -BackupTime "' + Trim(TimeEdit.Text) + '"';
  end;
  if NeedsAdmin then
  begin
    // The administrator's details go in a file only this user can read; the server deletes
    // it right after reading. Passwords never appear on a command line or in a log.
    // (Also after a failed first setup: no account exists yet; --if-none prevents a second one.)
    AdminFile := ExpandConstant('{tmp}\admin.json');
    if WizardSilent then
      FileCopy(ExpandConstant('{param:ADMINFILE|}'), AdminFile, False)
    else
    begin
      SetArrayLength(Lines, 1);
      Lines[0] := '{"first_name":"' + JsonEscape(Trim(AdminPage.Values[0])) + '","last_name":"' + JsonEscape(Trim(AdminPage.Values[1])) +
                  '","username":"' + JsonEscape(Trim(AdminPage.Values[2])) + '","email":"' + JsonEscape(Trim(AdminPage.Values[3])) +
                  '","password":"' + JsonEscape(AdminPage.Values[4]) + '"}';
      SaveStringsToUTF8FileWithoutBOM(AdminFile, Lines, False);
    end;
    Params := Params + ' -AdminFile "' + AdminFile + '"';
  end;

  WizardForm.StatusLabel.Caption := 'Configuring the server: database, migrations, HTTPS, services. This takes a few minutes...';
  WizardForm.ProgressGauge.Style := npbstMarquee;
  try
    ConfigureOk := Exec(PowerShellExe, Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) and (ResultCode = 0);
  finally
    WizardForm.ProgressGauge.Style := npbstNormal;
    DeleteFile(ExpandConstant('{tmp}\admin.json'));
  end;
  Log('configure.ps1 exit code ' + IntToStr(ResultCode));
  if not ConfigureOk then
    SuppressibleMsgBox('The files were installed, but configuring the server failed.' + #13#10#13#10 +
           'The log is in ' + ExpandConstant('{app}\logs') + ' (newest configure-*.log). Fix the problem it names, then open ' +
           'Start > BSU Inventory > BSU Inventory Server Manager and click "Repair configuration".', mbError, MB_OK, IDOK);
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then
    RunConfigure;
  if CurStep = ssDone then
    FileCopy(ExpandConstant('{log}'), ExpandConstant('{app}\logs\setup-{#AppVersion}-') + GetDateTimeString('yyyymmdd-hhnnss', #0, #0) + '.log', False);
end;

function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo, MemoComponentsInfo, MemoGroupInfo, MemoTasksInfo: String): String;
begin
  Result := MemoDirInfo + NewLine + NewLine;
  if IsExisting then
    Result := Result + 'Update of the existing installation:' + NewLine + Space + 'full backup first, settings and data kept, database migrations run.' + NewLine
  else
  begin
    Result := Result + 'Network:' + NewLine + Space + 'HTTPS port ' + HttpsPort + ', HTTP port ' + HttpPort + NewLine;
    if LanCheck.Checked then Result := Result + Space + 'LAN access on (firewall opened for private networks)' + NewLine
    else Result := Result + Space + 'LAN access off (this PC only)' + NewLine;
    Result := Result + NewLine + 'Administrator:' + NewLine + Space + Trim(AdminPage.Values[2]) + ' (Technical Staff)' + NewLine;
    Result := Result + NewLine + 'Backups:' + NewLine + Space + BackupPage.Values[0] + NewLine;
    if DailyCheck.Checked then Result := Result + Space + 'every day at ' + Trim(TimeEdit.Text) + NewLine;
  end;
  if MemoTasksInfo <> '' then Result := Result + NewLine + MemoTasksInfo;
end;

// ---------------------------------------------------------------------------
//  Uninstall: ask what to remove; never delete the database silently
// ---------------------------------------------------------------------------
function UninstallChoices: Boolean;
var
  Form: TSetupForm;
  Intro, Note: TNewStaticText;
  CbDb, CbUploads, CbConfig, CbLogs, CbBackups: TNewCheckBox;
  OkButton, CancelButton: TNewButton;
  Y: Integer;
begin
  Form := CreateCustomForm(ScaleX(560), ScaleY(400), False, True);
  try
    Form.Caption := 'Uninstall BSU Inventory System';
    Intro := TNewStaticText.Create(Form);
    Intro.Parent := Form;
    Intro.Left := ScaleX(16); Intro.Top := ScaleY(14); Intro.Width := Form.ClientWidth - ScaleX(32);
    Intro.AutoSize := False; Intro.Height := ScaleY(60); Intro.WordWrap := True;
    Intro.Caption := 'The program, its Windows services, firewall rule and backup task are removed, together with BSU''s own Laragon copy in ' +
                     ExpandConstant('{app}\laragon') + '. Other Laragon installations on this PC are not touched.' + #13#10#13#10 + 'Also remove:';
    Y := Intro.Top + Intro.Height + ScaleY(10);

    CbDb := TNewCheckBox.Create(Form); CbDb.Parent := Form; CbDb.Left := ScaleX(24); CbDb.Top := Y; CbDb.Width := Form.ClientWidth - ScaleX(40);
    CbDb.Caption := 'The database (inventory, stock movements, borrowing, users, audit log)'; Y := Y + ScaleY(26);
    CbUploads := TNewCheckBox.Create(Form); CbUploads.Parent := Form; CbUploads.Left := ScaleX(24); CbUploads.Top := Y; CbUploads.Width := CbDb.Width;
    CbUploads.Caption := 'Uploaded files and barcode images'; Y := Y + ScaleY(26);
    CbConfig := TNewCheckBox.Create(Form); CbConfig.Parent := Form; CbConfig.Left := ScaleX(24); CbConfig.Top := Y; CbConfig.Width := CbDb.Width;
    CbConfig.Caption := 'Configuration (.env, database login, HTTPS certificate authority) - only with the database'; Y := Y + ScaleY(26);
    CbLogs := TNewCheckBox.Create(Form); CbLogs.Parent := Form; CbLogs.Left := ScaleX(24); CbLogs.Top := Y; CbLogs.Width := CbDb.Width;
    CbLogs.Caption := 'Logs'; CbLogs.Checked := True; Y := Y + ScaleY(26);
    CbBackups := TNewCheckBox.Create(Form); CbBackups.Parent := Form; CbBackups.Left := ScaleX(24); CbBackups.Top := Y; CbBackups.Width := CbDb.Width;
    CbBackups.Caption := 'All backups in the backup folder (cannot be undone)'; Y := Y + ScaleY(34);

    Note := TNewStaticText.Create(Form);
    Note.Parent := Form; Note.Left := ScaleX(16); Note.Top := Y; Note.Width := Form.ClientWidth - ScaleX(32);
    Note.AutoSize := False; Note.Height := ScaleY(50); Note.WordWrap := True;
    Note.Caption := 'Kept items stay in ' + ExpandConstant('{app}') + '. Installing the BSU Inventory System into the same folder again continues with them.';

    OkButton := TNewButton.Create(Form); OkButton.Parent := Form;
    OkButton.Width := ScaleX(90); OkButton.Height := ScaleY(26);
    OkButton.Left := Form.ClientWidth - ScaleX(16 + 90 + 8 + 90); OkButton.Top := Form.ClientHeight - ScaleY(40);
    OkButton.Caption := 'Uninstall'; OkButton.ModalResult := mrOk;
    CancelButton := TNewButton.Create(Form); CancelButton.Parent := Form;
    CancelButton.Width := ScaleX(90); CancelButton.Height := ScaleY(26);
    CancelButton.Left := Form.ClientWidth - ScaleX(16 + 90); CancelButton.Top := OkButton.Top;
    CancelButton.Caption := 'Cancel'; CancelButton.ModalResult := mrCancel; CancelButton.Cancel := True;
    Form.ActiveControl := CancelButton;

    Result := Form.ShowModal = mrOk;
    UnDatabase := CbDb.Checked;
    UnUploads := CbUploads.Checked;
    UnConfig := CbConfig.Checked and CbDb.Checked;
    UnLogs := CbLogs.Checked;
    UnBackups := CbBackups.Checked;
  finally
    Form.Free;
  end;
end;

function InitializeUninstall: Boolean;
var
  Labels: TArrayOfString;
  Answer: Integer;
begin
  Result := True;
  UnBackup := False; UnDatabase := False; UnUploads := False; UnConfig := False; UnLogs := False; UnBackups := False;
  if UninstallSilent then
  begin
    // Silent: keep all data, unless /PURGE=1 (then a backup is made first unless /BACKUP=0;
    // the backup folder itself is always kept)
    if ExpandConstant('{param:PURGE|0}') = '1' then
    begin
      UnDatabase := True; UnUploads := True; UnConfig := True; UnLogs := True;
      UnBackup := ExpandConstant('{param:BACKUP|1}') <> '0';
    end;
    Exit;
  end;
  if not UninstallChoices then begin Result := False; Exit; end;
  if UnDatabase then
  begin
    SetArrayLength(Labels, 2);
    Labels[0] := 'Create Backup';
    Labels[1] := 'Continue Without Backup';
    Answer := TaskDialogMsgBox('The BSU Inventory database contains existing records.',
      'Deleting the database will permanently remove inventory data, users, transactions, and other system records.' + #13#10#13#10 +
      'Create a backup before continuing?', mbConfirmation, MB_YESNOCANCEL, Labels, 0);
    if Answer = IDCANCEL then begin Result := False; Exit; end;
    UnBackup := Answer = IDYES;
  end;
  if UnBackups and UnDatabase and not UnBackup then
    if MsgBox('You chose to delete the database AND all backups without a new backup. Nothing of the inventory records will remain.' + #13#10#13#10 +
              'Are you sure?', mbError, MB_YESNO or MB_DEFBUTTON2) <> IDYES then begin Result := False; Exit; end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  Params: String;
  ResultCode: Integer;
begin
  if CurUninstallStep <> usUninstall then Exit;
  Params := '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\manager\uninstall.ps1') + '" -InstallDir "' + ExpandConstant('{app}') + '"';
  if UnBackup then Params := Params + ' -Backup';
  if UnDatabase then Params := Params + ' -RemoveDatabase';
  if UnUploads then Params := Params + ' -RemoveUploads';
  if UnConfig then Params := Params + ' -RemoveConfig';
  if UnLogs then Params := Params + ' -RemoveLogs';
  if UnBackups then Params := Params + ' -RemoveBackups';
  Exec(PowerShellExe, Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  if ResultCode = 2 then
  begin
    // The backup failed: undo nothing destructive. The services are still in place.
    SuppressibleMsgBox('The backup failed, so no data was removed. The program files are removed now; the database and other data stay in ' +
           ExpandConstant('{app}') + '. Reinstall into the same folder to use them again.', mbError, MB_OK, IDOK);
    UnDatabase := False;
    Exec(PowerShellExe, '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\manager\uninstall.ps1') + '" -InstallDir "' +
         ExpandConstant('{app}') + '"', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  end
  else if ResultCode <> 0 then
    SuppressibleMsgBox('Some cleanup steps failed. A log is in ' + ExpandConstant('{%TEMP}') + ' (bsu-inventory-uninstall-*.log).', mbInformation, MB_OK, IDOK);
end;

param(
    # Принтер Windows (пусто — только сформировать ZPL в -Out)
    [string]$Printer = '',
    # Файл с описанием этикетки (UTF-8), по строке на элемент, поля через TAB
    [Parameter(Mandatory = $true)][string]$Spec,
    # Куда сохранить ZPL (пусто — не сохранять)
    [string]$Out = '',
    # Куда сохранить PNG-предпросмотр (пусто — не сохранять)
    [string]$Png = ''
)

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

$code = @"
using System;
using System.Collections.Generic;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Text;
using System.Runtime.InteropServices;
using System.Text;

public static class WmsLabelRender
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public class DOC_INFO_1
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDatatype;
    }

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool OpenPrinter(string pPrinterName, out IntPtr phPrinter, IntPtr pDefault);
    [DllImport("winspool.drv", EntryPoint = "ClosePrinter", SetLastError = true)]
    private static extern bool ClosePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterW", SetLastError = true, CharSet = CharSet.Unicode, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    private static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In, MarshalAs(UnmanagedType.LPStruct)] DOC_INFO_1 di);
    [DllImport("winspool.drv", EntryPoint = "EndDocPrinter", SetLastError = true)]
    private static extern bool EndDocPrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", EntryPoint = "StartPagePrinter", SetLastError = true)]
    private static extern bool StartPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", EntryPoint = "EndPagePrinter", SetLastError = true)]
    private static extern bool EndPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", EntryPoint = "WritePrinter", SetLastError = true)]
    private static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);

    public static void SendRaw(string printerName, byte[] bytes)
    {
        IntPtr h;
        if (!OpenPrinter(printerName, out h, IntPtr.Zero))
            throw new Exception("OpenPrinter failed: " + Marshal.GetLastWin32Error());
        try
        {
            DOC_INFO_1 di = new DOC_INFO_1();
            di.pDocName = "WMS label";
            di.pDatatype = "RAW";
            if (!StartDocPrinter(h, 1, di))
                throw new Exception("StartDocPrinter failed: " + Marshal.GetLastWin32Error());
            try
            {
                if (!StartPagePrinter(h)) throw new Exception("StartPagePrinter failed: " + Marshal.GetLastWin32Error());
                try
                {
                    IntPtr p = Marshal.AllocCoTaskMem(bytes.Length);
                    try
                    {
                        Marshal.Copy(bytes, 0, p, bytes.Length);
                        int written;
                        if (!WritePrinter(h, p, bytes.Length, out written) || written != bytes.Length)
                            throw new Exception("WritePrinter failed: " + Marshal.GetLastWin32Error());
                    }
                    finally { Marshal.FreeCoTaskMem(p); }
                }
                finally { EndPagePrinter(h); }
            }
            finally { EndDocPrinter(h); }
        }
        finally { ClosePrinter(h); }
    }

    private static List<string[]> Parse(string specText, out int w, out int h, out int copies)
    {
        w = 464; h = 320; copies = 1;
        var elems = new List<string[]>();
        foreach (string raw in specText.Replace("\r\n", "\n").Split('\n'))
        {
            if (raw.Length == 0) continue;
            string[] f = raw.Split('\t');
            if (f[0] == "SIZE" && f.Length >= 5)
            {
                w = int.Parse(f[1]); h = int.Parse(f[2]); copies = int.Parse(f[4]);
            }
            else if (f.Length >= 2)
            {
                elems.Add(f);
            }
        }
        if (w < 32) w = 32;
        if (h < 32) h = 32;
        if (copies < 1) copies = 1;
        return elems;
    }

    /// Рисует этикетку (1:1 в точках принтера).
    public static Bitmap Render(string specText)
    {
        int w, h, copies;
        var elems = Parse(specText, out w, out h, out copies);
        var bmp = new Bitmap(w, h, System.Drawing.Imaging.PixelFormat.Format32bppArgb);
        using (var g = Graphics.FromImage(bmp))
        {
            g.Clear(Color.White);
            g.SmoothingMode = SmoothingMode.None;
            g.PixelOffsetMode = PixelOffsetMode.Half;
            g.TextRenderingHint = TextRenderingHint.SingleBitPerPixelGridFit;

            foreach (string[] e in elems)
            {
                if (e[0] == "B" && e.Length >= 6)
                {
                    // B | x | y | barH | module | bits
                    int bx = int.Parse(e[1]), by = int.Parse(e[2]), bh = int.Parse(e[3]);
                    int mod = Math.Max(1, int.Parse(e[4]));
                    string bitsStr = e[5];
                    int cx = bx;
                    using (var br = new SolidBrush(Color.Black))
                    {
                        for (int i = 0; i < bitsStr.Length; i++)
                        {
                            if (bitsStr[i] == '1')
                                g.FillRectangle(br, cx, by, mod, bh);
                            cx += mod;
                        }
                    }
                }
                else if (e[0] == "T" && e.Length >= 9)
                {
                    // T | x | y | w | h | sizePx | bold | align(L/C/R) | text
                    int tx = int.Parse(e[1]), ty = int.Parse(e[2]), tw = int.Parse(e[3]), th = int.Parse(e[4]);
                    float size = float.Parse(e[5], System.Globalization.CultureInfo.InvariantCulture);
                    bool bold = e[6] == "1";
                    string align = e[7];
                    string text = e.Length > 8 ? string.Join("\t", e, 8, e.Length - 8) : "";
                    if (text.Length == 0) continue;

                    FontStyle style = bold ? FontStyle.Bold : FontStyle.Regular;
                    float sz = Math.Max(6f, size);
                    Font font = null;
                    for (int tries = 0; tries < 14; tries++)
                    {
                        font = new Font("Arial", sz, style, GraphicsUnit.Pixel);
                        SizeF m = g.MeasureString(text, font);
                        if (m.Height <= th + 1 || sz <= 7f) break;
                        font.Dispose();
                        sz -= Math.Max(1f, sz * 0.06f);
                    }
                    SizeF ms = g.MeasureString(text, font);
                    float x = tx;
                    if (align == "C") x = tx + (tw - ms.Width) / 2f;
                    else if (align == "R") x = tx + tw - ms.Width;
                    float y = ty + (th - ms.Height) / 2f;
                    using (var br = new SolidBrush(Color.Black))
                    {
                        g.DrawString(text, font, br, x, y);
                    }
                    font.Dispose();
                }
            }
        }
        return bmp;
    }

    /// ZPL с растровой этикеткой (^GFA) — работает на любом ZPL-принтере, кириллица включена.
    /// Несколько этикеток в одном файле разделяются строкой NEXT.
    public static string Build(string specText)
    {
        var sbAll = new StringBuilder();
        var blocks = specText.Replace("\r\n", "\n").Split(new string[] { "\nNEXT\n" }, StringSplitOptions.None);
        foreach (var block in blocks)
        {
            if (block.Trim().Length == 0) continue;
            sbAll.Append(BuildOne(block));
        }
        return sbAll.ToString();
    }

    public static string BuildOne(string specText)
    {
        int w, h, copies;
        Parse(specText, out w, out h, out copies);
        using (var bmp = Render(specText))
        {
            var data = bmp.LockBits(new Rectangle(0, 0, bmp.Width, bmp.Height),
                System.Drawing.Imaging.ImageLockMode.ReadOnly,
                System.Drawing.Imaging.PixelFormat.Format32bppArgb);
            int rowBytes = (bmp.Width + 7) / 8;
            int total = rowBytes * bmp.Height;
            byte[] bits = new byte[total];
            try
            {
                byte[] row = new byte[data.Stride];
                for (int yy = 0; yy < bmp.Height; yy++)
                {
                    Marshal.Copy(IntPtr.Add(data.Scan0, yy * data.Stride), row, 0, data.Stride);
                    for (int xx = 0; xx < bmp.Width; xx++)
                    {
                        int idx = xx * 4;
                        int b = row[idx], gg = row[idx + 1], r = row[idx + 2];
                        int lum = (b * 29 + gg * 150 + r * 77) >> 8;
                        if (lum < 160)
                        {
                            bits[yy * rowBytes + (xx >> 3)] |= (byte)(1 << (7 - (xx & 7)));
                        }
                    }
                }
            }
            finally
            {
                bmp.UnlockBits(data);
            }

            var hex = new StringBuilder(total * 2);
            for (int i = 0; i < total; i++) hex.Append(bits[i].ToString("X2"));

            var sb = new StringBuilder();
            sb.Append("^XA\n");
            sb.Append("^PW").Append(bmp.Width).Append('\n');
            sb.Append("^LL").Append(bmp.Height).Append('\n');
            sb.Append("^FO0,0^GFA,").Append(total).Append(',').Append(total).Append(',')
              .Append(rowBytes).Append(',').Append(hex.ToString()).Append("^FS\n");
            sb.Append("^PQ").Append(copies).Append('\n');
            sb.Append("^XZ\n");
            return sb.ToString();
        }
    }
}
"@

Add-Type -TypeDefinition $code -Language CSharp -ReferencedAssemblies System.Drawing

$specText = [System.IO.File]::ReadAllText($Spec, [System.Text.Encoding]::UTF8)

if ($Png -ne '') {
    $bmp = [WmsLabelRender]::Render($specText)
    $bmp.Save($Png, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
}

$zpl = [WmsLabelRender]::Build($specText)

if ($Out -ne '') {
    [System.IO.File]::WriteAllText($Out, $zpl, (New-Object System.Text.UTF8Encoding($false)))
}

if ($Printer -ne '') {
    $bytes = (New-Object System.Text.UTF8Encoding($false)).GetBytes($zpl)
    [WmsLabelRender]::SendRaw($Printer, $bytes)
}

Write-Output "OK"

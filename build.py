"""Build the Paymenter extension zip: copies the ArionPay PHP SDK into ArionPay/lib, then zips the ArionPay folder.
   python3 build.py  ->  dist/arionpay-paymenter.zip  (unzip into extensions/Gateways/)"""
import os, shutil, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
SDK = HERE.parent / "arionpay-php"
EXT = HERE / "ArionPay"
lib = EXT / "lib" / "arionpay-php"
if lib.exists():
    shutil.rmtree(lib)
(lib / "src").mkdir(parents=True)
for f in ("autoload.php", "LICENSE"):
    shutil.copy(SDK / f, lib / f)
for f in sorted((SDK / "src").glob("*.php")):
    shutil.copy(f, lib / "src" / f.name)
(HERE / "dist").mkdir(exist_ok=True)
out = HERE / "dist" / "arionpay-paymenter.zip"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(EXT):
        dirs.sort()
        for name in sorted(files):
            p = pathlib.Path(root) / name
            z.write(p, "ArionPay/" + str(p.relative_to(EXT)))
print(out, os.path.getsize(out), "bytes")

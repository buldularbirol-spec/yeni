# Galancy Muhasebe ve Operasyon Sistemi

Bu proje, `yeni muhasebe2.docx` ve `yeni muhasebe sql.docx` iceriklerine gore hazirlanmis bir baslangic sistemidir.

## Neler kuruldu

- Muhasebe ve operasyon modullerini kapsayan yonetim paneli
- MySQL kurulum ekrani
- Belgelerdeki ana basliklara gore hazirlanmis veritabani semasi
- Varsayilan rol, firma, sube ve admin kullanicisi seed kayitlari

## Dosyalar

- `index.php`: yonetim paneli
- `install.php`: veritabani kurulum arayuzu
- `database/schema.sql`: ana tablo yapilari
- `database/seed.sql`: varsayilan veriler
- `app_bootstrap.php`: ortak konfigurasyon ve PDO yardimcilari

## Kurulum

1. `http://localhost/muhasebe1/install.php` adresini acin.
2. MySQL baglanti bilgilerini girin.
3. `Sistemi Kur` butonuna basin.
4. Ardindan `http://localhost/muhasebe1/` adresinden paneli acin.

## Guvenlik notu

- Kurulum sonrasi yonetici sifresini hemen degistirin.
- Canli ortamda varsayilan kullanici bilgisi paylasmayin.

## Not

Bu teslim, belgeye uygun bir temel sistem ve veritabani omurgasi kurar. Detayli CRUD ekranlari, oturum yonetimi, entegrasyonlar ve raporlama katmani sonraki fazda genisletilebilir.

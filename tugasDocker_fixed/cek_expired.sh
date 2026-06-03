#!/bin/bash
# Script cek expired VPS harian
CHECKDATA2="/home/checkdata2/"
TANGGAL_SEKARANG=$(date +%s)

for file in $CHECKDATA2*; do
    [ -f "$file" ] || continue
    [ "$(basename $file)" = "locked" ] && continue
    DATA=$(cat "$file")
    USERNAME=$(echo $DATA | cut -d',' -f1 | tr -d ' ')
    PAKET=$(echo $DATA | cut -d',' -f7 | tr -d ' ')
    EXPIRED=$(echo $DATA | cut -d',' -f8 | tr -d ' ')
    CONTAINER=$(echo $DATA | cut -d',' -f9 | tr -d ' ' | tr -d '.')
    WA=$(echo $DATA | cut -d',' -f4 | tr -d ' ')

    # Konversi tanggal expired dd-mm-yyyy ke timestamp
    EXP_TS=$(date -d "$(echo $EXPIRED | awk -F'-' '{print $3"-"$2"-"$1}')" +%s 2>/dev/null)

    [ -z "$EXP_TS" ] && continue

    # Hitung selisih hari
    SELISIH=$(( ($EXP_TS - $TANGGAL_SEKARANG) / 86400 ))

    if [ $SELISIH -le 0 ]; then
        docker stop $CONTAINER 2>/dev/null
        echo "[$USERNAME] EXPIRED - stopped"
    elif [ $SELISIH -le 3 ]; then
        # Kirim notifikasi WA H-3
        WA_TOKEN=$(grep WATOKEN /var/www/html/tugasDocker/wa.conf | cut -d= -f2)
        curl -s -X POST "https://api.fonnte.com/send" \
          -H "Authorization: $WA_TOKEN" \
          -H "Content-Type: application/json" \
          -d "{\"target\":\"$WA\",\"message\":\"⚠️ VPS Anda akan expired dalam $SELISIH hari!\nUsername: $USERNAME\nExpired: $EXPIRED\nSegera perpanjang di https://tugaspkl.my.id\"}" > /dev/null
        echo "[$USERNAME] H-$SELISIH notif WA terkirim"
    fi
done

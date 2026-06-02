#!/bin/bash
# Script cek expired VPS harian
CHECKDATA2="/home/checkdata2/"
TANGGAL_SEKARANG=$(date +%s)

for file in $CHECKDATA2*; do
    [ -f "$file" ] || continue
    [ "$(basename $file)" = "locked" ] && continue
    
    DATA=$(cat "$file")
    USERNAME=$(echo $DATA | cut -d',' -f1 | tr -d ' ')
    EXPIRED=$(echo $DATA | cut -d',' -f6 | tr -d ' ' | tr -d '.')
    CONTAINER=$(echo $DATA | cut -d',' -f7 | tr -d ' ')
    WA=$(echo $DATA | cut -d',' -f4 | tr -d ' ')
    
    # Konversi tanggal expired ke timestamp
    EXP_TS=$(date -d "$EXPIRED" +%s 2>/dev/null)
    
    # Hitung selisih hari
    SELISIH=$(( ($EXP_TS - $TANGGAL_SEKARANG) / 86400 ))
    
    # Cek expired
    if [ $SELISIH -le 0 ]; then
        docker stop $CONTAINER 2>/dev/null
        echo "[$CONTAINER] EXPIRED - stopped"
    fi
done

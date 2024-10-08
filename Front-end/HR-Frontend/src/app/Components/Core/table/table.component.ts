import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { EmployeeDashService } from '../../../Services/employee-dash.service';
import { EmployeeDashboard } from '../../../Models/employee-dashboard';
import { ClockEventService } from '../../../Services/clock-event.service';

@Component({
  selector: 'app-table',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './table.component.html',
  styleUrl: './table.component.css'
})
export class TableComponent {
  token: any = "";
  Userclocks: EmployeeDashboard[] = [];
  pageNumber = 1;
  showOtherClocks: boolean = false;
  PagesNumber: number = 1;
  CurrentPageNumber: number = 1;
  pages: number[] = [];
  SelectedDate: string = ""
  isDateSelected = false

  rowNumber: boolean[] = [];

  
  constructor(
    public empDashserv: EmployeeDashService,
    private clockEventService: ClockEventService

  ) {
  }

  ngOnInit(): void {
    this.GetClockss(1);

    this.clockEventService.clockedIn$.subscribe(() => {
      this.GetClockss(this.pageNumber);
    });
  }


  GetClockss(pgNumb: number) {
    this.pageNumber = pgNumb;
    this.token = localStorage.getItem("token");
    this.empDashserv.GetClocks(this.token, pgNumb).subscribe(
      (d: any) => {
        this.Userclocks = d.data.clocks;
        this.rowNumber = new Array(this.Userclocks.length).fill(false);
        this.generatePages();
      }
    );
  }

  generatePages() {
    this.pages = [];
    for (let i = 1; i <= this.PagesNumber; i++) {
      this.pages.push(i);
    }
  }

  getNextPage() {
    this.CurrentPageNumber++;
    this.GetClockss(this.CurrentPageNumber);
  }

  getPrevPage() {
    this.CurrentPageNumber--;
    this.GetClockss(this.CurrentPageNumber);
  }

  toggleOtherClocks(index: number): void {

    this.rowNumber[index] = !this.rowNumber[index];
    this.showOtherClocks = this.rowNumber.some(state => state);

  }

  convertUTCToEgyptLocalTime(utcTimeStr: string): string {
    const [time, period] = utcTimeStr.split(/(AM|PM)/);
    let [hours, minutes] = time.split(':').map(Number);
    if (period === 'PM' && hours !== 12) {
      hours += 12;
    }
    if (period === 'AM' && hours === 12) {
      hours = 0;
    }
    const currentDate = new Date();
    const utcDate = new Date(Date.UTC(currentDate.getUTCFullYear(), currentDate.getUTCMonth(), currentDate.getUTCDate(), hours, minutes));
    const egyptTimeZone = 'Africa/Cairo';
    const localDate = new Date(utcDate.toLocaleString('en-US', { timeZone: egyptTimeZone }));
    let localHours = localDate.getHours();
    const localMinutes = localDate.getMinutes();
    const localPeriod = localHours >= 12 ? 'PM' : 'AM';
    localHours = localHours % 12 || 12; // Converts '0' hours to '12'
    const formattedHours = String(localHours).padStart(2, '0');
    const formattedMinutes = String(localMinutes).padStart(2, '0');
    return `${formattedHours}:${formattedMinutes} ${localPeriod}`;
  }

  searchByDate() {
    if (this.SelectedDate) {
      this.empDashserv.SearchByDateInClockByToken(this.SelectedDate).subscribe(
        (d: any) => {
          this.Userclocks = []
          this.Userclocks = d.data.clocks;
          this.PagesNumber = 1;
        },
        (error) => {
          if (error.status == 404) {
            this.Userclocks = []
          }
          this.PagesNumber = 1;
        }
      );
      this.isDateSelected = true
    } else {
    }
  }

  ClearSearch() {
    this.isDateSelected = false
    this.SelectedDate = ''
    this.GetClockss(1);
    this.clockEventService.clockedIn$.subscribe(() => {
      this.GetClockss(this.pageNumber);
    });

  }
}

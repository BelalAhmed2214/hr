import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

@Component({
  selector: 'app-side-bar',
  standalone: true,
  imports: [RouterLink, RouterOutlet, RouterLinkActive,CommonModule],
  templateUrl: './side-bar.component.html',
  styleUrl: './side-bar.component.css'
})
export class SideBarComponent {
  menuItems = [
    { label: 'Dashboard', icon: 'fa-regular fa-table-list' ,  route: '/Dashboard' },
    { label: 'Sign Out', icon: 'fa-regular fa-sign-out'  ,  route: '/Login' },
  ];

  activeIndex: number | null = null;

  setActiveIndex(index: number): void {
    this.activeIndex = index;
  }
}